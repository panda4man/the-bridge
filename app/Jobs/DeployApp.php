<?php

namespace App\Jobs;

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Models\App;
use App\Models\Deployment;
use App\Services\ContainerStatus;
use App\Services\DeploySteps;
use App\Services\GitService;
use App\Services\Process\ProcessRunner;
use App\Services\SlackNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Ported from reference/src/jobs/deployApp.ts.
 *
 * A failed deploy is a successful job: the catch block in handle() records
 * the failure on the Deployment/App rows and never rethrows. $tries only
 * matters for the two "not found" throws at the top of handle() — those are
 * the ONLY errors allowed to escape this method and reach Laravel's queue
 * retry mechanism. Rethrowing a mid-pipeline failure would make the worker
 * re-run (and re-log, and re-notify, and re-auto-rollback) the entire deploy
 * up to three times.
 *
 * The reference injects `composeRunner`/`execStep` closures so its tests can
 * swap in a mock without touching the real spawn() call. This port has no
 * equivalent constructor option — it fakes one level lower, at
 * App\Services\Process\ProcessRunner (the same seam GitService and
 * ContainerStatus already use), via the container. That means tests using
 * Tests\Support\FakeProcessRunner see the exact `docker compose ...` argv
 * this job builds, which the reference's own tests never pinned.
 *
 * Every process invocation in the build phase (git pull/checkout, docker
 * compose pull/down/up, docker compose exec) goes through
 * ProcessRunner::run() with `idleTimeout` set and `timeout: null` — a stall
 * (no output for the idle window) comes back as a well-formed ProcessResult
 * with `timedOut = true`, never a thrown exception (see
 * App\Services\Process\ProcessRunner's docblock). This job treats a stall
 * exactly like a non-zero exit: it logs the reference's stall message and
 * feeds exit code 1 into the same 3-attempt retry loop compose failures use.
 *
 * Never reads ProcessResult->output for the streaming compose/exec calls —
 * only the short git rev-parse/log calls (via GitService) do that. Log
 * persistence for the long-running steps happens exclusively through the
 * $onOutput callback into Deployment::appendLog(), so a 40-minute build's
 * output never has to sit twice in worker memory.
 */
class DeployApp implements ShouldQueue
{
    use Queueable;

    /**
     * Only the "not found" throws in handle() are meant to trigger a queue
     * retry. See the class docblock.
     */
    public $tries = 3;

    /**
     * Laravel's queue worker kills a job at 60 seconds by default. A real
     * `docker compose up -d --build` can run far longer than that. This
     * disables the job-level timeout; Phase 7's supervisord `queue:work`
     * invocation must ALSO be started with --timeout=0 — the job property
     * alone is not sufficient if the worker process itself enforces a
     * shorter timeout (see docs/porting-notes.md).
     */
    public $timeout = 0;

    public function __construct(public readonly int $deploymentId) {}

    /**
     * GitService, ContainerStatus, and ProcessRunner are all resolved by the
     * container (queue worker, or a direct app()->call([$job, 'handle']) in
     * tests). GitService/ContainerStatus each take a ProcessRunner via their
     * own constructor, itself resolved from the same container binding —
     * binding Tests\Support\FakeProcessRunner as that singleton in a test
     * makes every shell-out this job performs, directly or through those two
     * services, observable without a real git remote or Docker daemon.
     */
    public function handle(GitService $git, ContainerStatus $containerStatus, ProcessRunner $runner): void
    {
        $dep = Deployment::find($this->deploymentId);
        if (! $dep) {
            throw new RuntimeException("Deployment {$this->deploymentId} not found");
        }

        $app = App::find($dep->app_id);
        if (! $app) {
            throw new RuntimeException("App {$dep->app_id} not found");
        }

        $dep->status = DeploymentStatus::Running;
        $dep->started_at = now();
        $dep->save();

        $app->status = AppStatus::Deploying;
        $app->save();

        try {
            $this->runGitPhase($git, $dep, $app);
            $this->runComposePhase($runner, $dep, $app);
            $this->runPostDeployPhase($runner, $containerStatus, $dep, $app);

            $dep->status = DeploymentStatus::Success;
            $dep->finished_at = now();
            $dep->save();

            $app->status = AppStatus::Success;
            $app->save();

            $this->notify($dep);
        } catch (Throwable $e) {
            $dep->appendLog("\nERROR: {$e->getMessage()}\n");
            $dep->status = DeploymentStatus::Failed;
            $dep->finished_at = now();
            $dep->save();

            $app->status = AppStatus::Failed;
            $app->save();

            $this->notify($dep);

            $this->autoRollback($dep);
        }
    }

    private function runGitPhase(GitService $git, Deployment $dep, App $app): void
    {
        if ($dep->rollback_sha) {
            $dep->appendLog("=== git checkout {$dep->rollback_sha} ===\n");
            $fetchOut = $git->pull($app->path, $app->branch);
            $dep->appendLog($fetchOut."\n");
            $git->checkout($app->path, $dep->rollback_sha);
            $dep->appendLog("Checked out {$dep->rollback_sha}\n");
        } else {
            $dep->appendLog("=== git pull ===\n");
            $pullOutput = $git->pull($app->path, $app->branch);
            $dep->appendLog($pullOutput."\n");
        }

        $dep->commit_sha = $git->revParseHead($app->path);
        $dep->commit_message = $git->lastCommitSubject($app->path);
        $dep->save();
    }

    private function runComposePhase(ProcessRunner $runner, Deployment $dep, App $app): void
    {
        foreach (['pull', 'down', 'up -d --build --remove-orphans'] as $subCmd) {
            $success = false;

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $label = $attempt > 1 ? " (attempt {$attempt})" : '';
                $dep->appendLog("=== docker compose {$subCmd}{$label} ===\n");

                $exit = $this->runCompose($runner, $subCmd, $app->path, $dep);

                if ($exit === 0) {
                    $success = true;
                    break;
                }

                if ($attempt < 3) {
                    $dep->appendLog("Retrying in 5s...\n");
                    Sleep::for(5)->seconds();
                }
            }

            if (! $success) {
                throw new RuntimeException("docker compose {$subCmd} failed after 3 attempts");
            }
        }
    }

    private function runPostDeployPhase(ProcessRunner $runner, ContainerStatus $containerStatus, Deployment $dep, App $app): void
    {
        $resolved = DeploySteps::resolve($app);
        $steps = $resolved['steps'];
        $source = $resolved['source'];

        if (count($steps) === 0) {
            return;
        }

        $count = count($steps);
        $plural = $count === 1 ? '' : 's';
        $dep->appendLog("=== post-deploy ({$count} step{$plural}, source: {$source}) ===\n");

        $containers = $containerStatus->forWorkDir($app->path);
        $runningServices = [];
        foreach ($containers as $container) {
            if (($container['state'] ?? null) === 'running') {
                $runningServices[$container['service']] = true;
            }
        }

        // Pre-flight must complete for ALL steps before ANY exec runs — a
        // separate loop over $steps, not folded into the exec loop below.
        foreach ($steps as $step) {
            if (! isset($runningServices[$step['service']])) {
                throw new RuntimeException("post-deploy: service \"{$step['service']}\" not running — check service name in bridge.yml or deploy_steps");
            }
        }

        foreach ($steps as $step) {
            $dep->appendLog("--- exec {$step['service']}: {$step['run']} ---\n");

            $exit = $this->runExecStep($runner, $step['service'], $step['run'], $app->path, $dep);

            if ($exit !== 0) {
                throw new RuntimeException("post-deploy step failed: {$step['service']}: {$step['run']}");
            }
        }
    }

    private function runCompose(ProcessRunner $runner, string $subCmd, string $workDir, Deployment $dep): int
    {
        $composeFile = rtrim($workDir, '/').'/docker-compose.yml';
        $command = array_merge(
            ['docker', 'compose', '-f', $composeFile],
            explode(' ', $subCmd),
        );

        $env = array_merge(['DOCKER_PROGRESS' => 'plain'], $this->sshEnv());
        $idleTimeout = str_starts_with($subCmd, 'pull') ? 60.0 : 300.0;

        try {
            $result = $runner->run(
                $command,
                $workDir,
                $env,
                timeout: null,
                idleTimeout: $idleTimeout,
                onOutput: static function (string $type, string $chunk) use ($dep): void {
                    $dep->appendLog($chunk);
                },
            );
        } catch (Throwable $e) {
            // Mirrors the reference's spawnWithStall() proc.on('error')
            // handler: a spawn failure (e.g. the docker binary itself
            // missing) is logged and treated as exit code 1, NOT rethrown —
            // it must still go through the 3-attempt retry loop like any
            // other non-zero exit, not escape straight to the outer catch
            // after a single attempt.
            $dep->appendLog("\nERROR: {$e->getMessage()}\n");

            return 1;
        }

        if ($result->timedOut) {
            $seconds = (int) $idleTimeout;
            $dep->appendLog("\nERROR: process stalled — no output for {$seconds}s, killed.\n");

            return 1;
        }

        return $result->exitCode;
    }

    private function runExecStep(ProcessRunner $runner, string $service, string $run, string $workDir, Deployment $dep): int
    {
        $composeFile = rtrim($workDir, '/').'/docker-compose.yml';
        $command = ['docker', 'compose', '-f', $composeFile, 'exec', '-T', $service, 'sh', '-c', $run];

        try {
            $result = $runner->run(
                $command,
                $workDir,
                $this->sshEnv(),
                timeout: null,
                idleTimeout: 60.0,
                onOutput: static function (string $type, string $chunk) use ($dep): void {
                    $dep->appendLog($chunk);
                },
            );
        } catch (Throwable $e) {
            // Same spawn-failure contract as runCompose() above — exec steps
            // don't retry (per spec), but a spawn error must still surface
            // as a normal exit-code-1 failure, not an uncaught throw.
            $dep->appendLog("\nERROR: {$e->getMessage()}\n");

            return 1;
        }

        if ($result->timedOut) {
            $dep->appendLog("\nERROR: process stalled — no output for 60s, killed.\n");

            return 1;
        }

        return $result->exitCode;
    }

    /**
     * Same resolution + existence-guard as GitService::envFor() (Phase 2),
     * duplicated rather than shared: this is a compose/exec-argv concern,
     * not a git one, and GitService's version is private to that class.
     *
     * @return array<string, string>
     */
    private function sshEnv(): array
    {
        $sshKeyPath = (string) config('bridge.ssh_key_path');

        if ($sshKeyPath !== '' && file_exists($sshKeyPath)) {
            return [
                'GIT_SSH_COMMAND' => "ssh -i {$sshKeyPath} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null",
            ];
        }

        return [];
    }

    /**
     * Guarded by `if (! $dep->rollback_sha)` — a deployment that is ITSELF a
     * rollback never chains another one. Without this guard a rollback that
     * fails would enqueue a rollback of a rollback, indefinitely.
     */
    private function autoRollback(Deployment $dep): void
    {
        if ($dep->rollback_sha) {
            return;
        }

        $previous = Deployment::findLastSuccessful($dep->app_id, $dep->id);

        if ($previous && $previous->commit_sha) {
            $dep->appendLog("Auto-rolling back to {$previous->commit_sha}\n");

            $rollback = Deployment::create([
                'app_id' => $dep->app_id,
                'status' => DeploymentStatus::Pending,
                'rollback_sha' => $previous->commit_sha,
            ]);

            self::dispatch($rollback->id);
        } else {
            $dep->appendLog("No previous successful deployment — skipping auto-rollback\n");
        }
    }

    /**
     * Slack is fire-and-forget. SlackNotifier::notify() throwing must never
     * fail the deploy — and, since this is also called from the catch block
     * ahead of autoRollback(), it must never prevent the auto-rollback that
     * follows it either.
     */
    private function notify(Deployment $dep): void
    {
        try {
            SlackNotifier::notify($dep);
        } catch (Throwable) {
            // fire-and-forget
        }
    }
}
