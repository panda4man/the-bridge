<?php

namespace Tests\Unit;

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Jobs\DeployApp;
use App\Models\App;
use App\Models\Deployment;
use App\Models\Setting;
use App\Services\Process\ProcessResult;
use App\Services\Process\ProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

/**
 * Ported from reference/tests/Unit/deployApp.test.ts (8 cases). The
 * reference clones a real public GitHub repo and shells real git/docker —
 * per this port's testability requirement (no network, no real git remote,
 * no real docker), every case here drives App\Services\Process\ProcessRunner
 * via Tests\Support\FakeProcessRunner instead, bound into the container so
 * DeployApp, GitService, and ContainerStatus all see the same fake.
 *
 * The job is invoked with `app()->call([new DeployApp($id), 'handle'])`
 * rather than `DeployApp::dispatch($id)` in every test — deliberately.
 * QUEUE_CONNECTION is `sync` in phpunit.xml, so a real ::dispatch() call
 * runs synchronously in-process; calling handle() directly instead means
 * the ONLY place a nested dispatch can happen is the auto-rollback's
 * `self::dispatch($rollback->id)`, which the relevant tests below fake via
 * Bus::fake() and assert on explicitly, rather than letting a second
 * deploy actually run recursively inside the test.
 */
class DeployAppTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function bindRunner(): FakeProcessRunner
    {
        $runner = new FakeProcessRunner;
        $this->app->instance(ProcessRunner::class, $runner);

        return $runner;
    }

    private function makeApp(array $overrides = []): App
    {
        return App::factory()->create(array_merge([
            'path' => '/repos/'.bin2hex(random_bytes(6)),
        ], $overrides));
    }

    /**
     * Creates an App backed by a REAL temp directory containing bridge.yml —
     * needed only for the handful of tests proving DeploySteps::resolve()'s
     * "source: repo" branch, since App::path in every other test is a
     * synthetic, non-existent path (DeploySteps::resolve()'s file_exists()
     * check on it is harmlessly false, and nothing else in the job touches
     * the filesystem).
     */
    private function makeAppWithBridgeYml(string $yaml, array $overrides = []): App
    {
        $dir = sys_get_temp_dir().'/deploy-app-'.bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/bridge.yml', $yaml);
        $this->tempDirs[] = $dir;

        return $this->makeApp(array_merge(['path' => $dir], $overrides));
    }

    private function runHandle(int $deploymentId): void
    {
        app()->call([new DeployApp($deploymentId), 'handle']);
    }

    /**
     * Queues the four calls GitService::pull() makes when the working copy
     * is already checked out on the target branch (config, fetch,
     * rev-parse, pull) — the common case, since App::factory() defaults
     * branch to 'main' and this queues rev-parse's answer to match.
     */
    private function queueGitPull(FakeProcessRunner $runner, string $branch = 'main', string $pullOutput = 'Already up to date.'): FakeProcessRunner
    {
        return $runner
            ->queueSuccess()
            ->queueSuccess()
            ->queueSuccess($branch)
            ->queueSuccess($pullOutput);
    }

    /**
     * Queues the two short git calls runGitPhase() makes after pulling —
     * `git rev-parse HEAD` and `git log -1 --format=%s`.
     *
     * Answers off the ARGV, not positionally (QC round 2, finding 1). A pair
     * of canned positional responses cannot tell
     *
     *     $dep->commit_sha     = $git->revParseHead(...);
     *     $dep->commit_message = $git->lastCommitSubject(...);
     *
     * apart from the same two assignments swapped: swapping them also swaps
     * which response is dequeued first, so both columns still receive the
     * right value and the suite stays green. That mutant is not cosmetic —
     * a commit SUBJECT in commit_sha is what autoRollback() would later hand
     * to `git checkout`. Answering off the argv makes the swap observable,
     * and fail()ing on anything else pins that the queue is aligned with the
     * calls the job actually makes.
     */
    private function queueCommitCapture(FakeProcessRunner $runner, string $sha, string $message): FakeProcessRunner
    {
        $answer = function (array $command) use ($sha, $message): ProcessResult {
            // `git rev-parse HEAD` — NOT pull()'s `rev-parse --abbrev-ref HEAD`.
            if (array_slice($command, 1) === ['rev-parse', 'HEAD']) {
                return new ProcessResult(0, $sha."\n", '');
            }

            if (array_slice($command, 1) === ['log', '-1', '--format=%s']) {
                return new ProcessResult(0, $message."\n", '');
            }

            $this->fail('queueCommitCapture: expected the commit-capture git calls, got: '.implode(' ', $command));
        };

        return $runner
            ->queueCallable(static fn (array $command): ProcessResult => $answer($command))
            ->queueCallable(static fn (array $command): ProcessResult => $answer($command));
    }

    // --- Reference case 1: success path ---

    public function test_marks_deployment_and_app_success_when_all_commands_exit_zero(): void
    {
        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess("mock compose output\n")
            ->queueSuccess("mock compose output\n")
            ->queueSuccess("mock compose output\n");

        $this->runHandle($dep->id);

        $updated = $dep->fresh();
        $updatedApp = $app->fresh();

        $this->assertSame(DeploymentStatus::Success, $updated->status);
        $this->assertStringContainsString('mock compose output', $updated->log);
        $this->assertNotNull($updated->finished_at);
        $this->assertSame(AppStatus::Success, $updatedApp->status);

        // B16 (QC fix round): the "=== git pull ===" header and the pull
        // output itself were never asserted on the (much more common)
        // non-rollback success path — only their ABSENCE was checked, on
        // the rollback test below, which stays green even if this whole
        // branch's logging were deleted outright.
        $this->assertStringContainsString('=== git pull ===', $updated->log);
        $this->assertStringContainsString('Already up to date.', $updated->log);
    }

    // --- Reference case 2: compose failure ---

    /**
     * Also pins "a deploy failure does not throw": calling runHandle()
     * below with no expectException() means an uncaught exception from a
     * mutated catch block (e.g. a stray `throw $e;` added at the end) would
     * fail this test with a PHPUnit Error, not just a wrong assertion.
     */
    public function test_marks_deployment_and_app_failed_when_compose_exits_non_zero_after_exhausting_retries(): void
    {
        Sleep::fake();

        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueFailure(1, '', "compose error output\n")
            ->queueFailure(1, '', "compose error output\n")
            ->queueFailure(1, '', "compose error output\n");

        $this->runHandle($dep->id);

        $updated = $dep->fresh();
        $updatedApp = $app->fresh();

        $this->assertSame(DeploymentStatus::Failed, $updated->status);
        $this->assertSame(AppStatus::Failed, $updatedApp->status);
        $this->assertStringContainsString('docker compose pull failed after 3 attempts', $updated->log);

        // B7 (QC fix round): finished_at on the FAILURE path was never
        // asserted — only the success-path assertion existed, and deleting
        // the failure-path `$dep->finished_at = now();` line left the full
        // suite green. SlackNotifier's duration text is computed from
        // started_at/finished_at, so a failed deploy silently losing its
        // end time breaks Phase 6's UI, not just this field in isolation.
        $this->assertNotNull($updated->finished_at);

        // B2 (QC fix round): stderr reaching the log was asserted for
        // success-path stdout but never for a failure's stderr — compose
        // writes nearly all of its real build progress to stderr, so this
        // is the difference between a useful failure log and an empty one.
        $this->assertStringContainsString('compose error output', $updated->log);
    }

    /**
     * B1 (QC fix round): hoisting `$success = false;` out of the
     * `foreach (['pull', 'down', 'up ...'] as $subCmd)` loop survives the
     * full suite — once `pull` succeeds the flag stays `true`, so `down`/
     * `up` failing all 3 attempts still reports the deploy `successful`.
     * Every previously-existing failure test failed on `pull`, the FIRST
     * sub-command, so `{$subCmd}` in the thrown message was only ever
     * observed as literally "pull". These two tests let `pull` succeed and
     * fail a LATER sub-command instead.
     */
    public function test_compose_failure_on_down_after_pull_succeeds_marks_the_deploy_failed(): void
    {
        Sleep::fake();

        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess() // pull
            ->queueFailure(1, '', 'down error')
            ->queueFailure(1, '', 'down error')
            ->queueFailure(1, '', 'down error');

        $this->runHandle($dep->id);

        $updated = $dep->fresh();
        $updatedApp = $app->fresh();

        $this->assertSame(DeploymentStatus::Failed, $updated->status);
        $this->assertSame(AppStatus::Failed, $updatedApp->status);
        $this->assertStringContainsString("\nERROR: docker compose down failed after 3 attempts\n", $updated->log);

        // "up" must never have been reached.
        $composeCalls = array_values(array_filter($runner->calls, static fn (array $c) => ($c['command'][0] ?? null) === 'docker'));
        $this->assertCount(4, $composeCalls); // 1 pull + 3 down attempts
        foreach ($composeCalls as $call) {
            $this->assertNotSame('up', $call['command'][4]);
        }
    }

    public function test_compose_failure_on_up_after_pull_and_down_succeed_marks_the_deploy_failed(): void
    {
        Sleep::fake();

        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess() // pull
            ->queueSuccess() // down
            ->queueFailure(1, '', 'up error')
            ->queueFailure(1, '', 'up error')
            ->queueFailure(1, '', 'up error');

        $this->runHandle($dep->id);

        $updated = $dep->fresh();
        $updatedApp = $app->fresh();

        $this->assertSame(DeploymentStatus::Failed, $updated->status);
        $this->assertSame(AppStatus::Failed, $updatedApp->status);
        $this->assertStringContainsString(
            "\nERROR: docker compose up -d --build --remove-orphans failed after 3 attempts\n",
            $updated->log
        );
    }

    // --- Reference case 3: commit sha/message capture ---

    public function test_stores_commit_sha_and_message_after_successful_deploy(): void
    {
        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess()->queueSuccess()->queueSuccess();

        $this->runHandle($dep->id);

        $updated = $dep->fresh();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $updated->commit_sha);
        $this->assertSame('aabbccddee112233445566778899aabbccddee11', $updated->commit_sha);
        // B17 (QC fix round): assertNotEmpty() passes for ANY non-empty
        // string, so a mutation that reordered/garbled which queued
        // response feeds commit_message would sail through. assertSame()
        // pins the exact value queued via queueCommitCapture().
        $this->assertSame('Fix the thing', $updated->commit_message);
    }

    /**
     * QC round 2, finding 5: deleting the `$dep->save()` at the end of
     * runGitPhase() survives the whole suite — commit_sha/commit_message
     * stay dirty on the model and are flushed by the later save() on either
     * the success or the catch path, so the final row looks identical.
     *
     * It is not an equivalent mutation. reference/src/jobs/deployApp.ts:114
     * persists the SHA IMMEDIATELY, before the compose phase, and
     * $timeout = 0 exists precisely so that phase can run for 40 minutes.
     * Under the mutant deployments.commit_sha stays NULL for that entire
     * window — Phase 5/6's live polling would show no commit for the whole
     * build, and a worker killed mid-compose loses the SHA outright.
     */
    public function test_commit_sha_and_message_are_persisted_before_the_compose_phase_starts(): void
    {
        $sha = 'aabbccddee112233445566778899aabbccddee11';

        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, $sha, 'Fix the thing');

        $observed = null;
        $runner->queueCallable(function (array $command) use ($dep, &$observed): ProcessResult {
            // The first compose call — `docker compose -f <file> pull`.
            $this->assertSame('pull', $command[4]);
            $observed = $dep->fresh()->only(['commit_sha', 'commit_message']);

            return new ProcessResult(0, '', '');
        })->queueSuccess()->queueSuccess(); // down, up

        $this->runHandle($dep->id);

        $this->assertSame(DeploymentStatus::Success, $dep->fresh()->status);
        $this->assertNotNull($observed, 'The compose phase never ran.');
        $this->assertSame($sha, $observed['commit_sha']);
        $this->assertSame('Fix the thing', $observed['commit_message']);
    }

    /**
     * QC round 2, finding 2: every compose failure in this file is exit code
     * 1 — `queueFailure(1, ...)` or a stall, which also reports 1. That
     * leaves `if ($exit === 0)` indistinguishable from `if ($exit !== 1)`,
     * and `return $result->exitCode;` indistinguishable from
     * `return $result->exitCode === 0 ? 0 : 1;`; both mutants stay green.
     * Under the first one a `docker compose up` exiting 125 (daemon
     * unreachable) or 127 (binary missing) is treated as SUCCESS: the
     * deployment and app are marked success and the post-deploy steps run
     * against containers that never started.
     */
    public function test_compose_exit_code_other_than_zero_or_one_still_fails_the_deploy(): void
    {
        Sleep::fake();

        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueFailure(125, '', 'Cannot connect to the Docker daemon')
            ->queueFailure(125, '', 'Cannot connect to the Docker daemon')
            ->queueFailure(125, '', 'Cannot connect to the Docker daemon');

        $this->runHandle($dep->id);

        $updated = $dep->fresh();

        $this->assertSame(DeploymentStatus::Failed, $updated->status);
        $this->assertSame(AppStatus::Failed, $app->fresh()->status);
        $this->assertStringContainsString("\nERROR: docker compose pull failed after 3 attempts\n", $updated->log);
        // Exit 125 must go through the same 3-attempt retry loop as exit 1.
        $this->assertSame(2, substr_count($updated->log, 'Retrying in 5s...'));
    }

    /**
     * QC round 2, finding 3: every throwable driven through handle() in this
     * file is a RuntimeException, so `catch (Throwable $e)` at
     * DeployApp::handle() and `catch (RuntimeException $e)` are
     * indistinguishable — the narrower mutant stays green.
     *
     * The class docblock claims the two "not found" throws are the ONLY
     * errors allowed to escape handle(). Under the mutant an Error (a
     * TypeError from a bad cast, say) escapes instead: the deployment is
     * left stuck in `running` with no failure recorded, and $tries = 3
     * re-runs the ENTIRE deploy — re-logging, re-notifying,
     * re-auto-rolling-back — three times. Exactly what the docblock says
     * must never happen.
     *
     * The Error is queued at GitService::pull()'s first call (`git config
     * safe.directory *`), which is deliberately NOT routed through that
     * class's mustRun() and has no try/catch of its own, so the throwable
     * reaches handle()'s catch block instead of being absorbed by
     * runCompose()'s or runExecStep()'s own `catch (Throwable)`.
     */
    public function test_a_throwable_that_is_not_a_runtime_exception_is_still_recorded_as_a_deploy_failure(): void
    {
        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $runner->queueThrowable(new \Error('boom'));

        $this->runHandle($dep->id);

        $updated = $dep->fresh();

        $this->assertSame(DeploymentStatus::Failed, $updated->status);
        $this->assertSame(AppStatus::Failed, $app->fresh()->status);
        $this->assertNotNull($updated->finished_at);
        $this->assertStringContainsString("\nERROR: boom\n", $updated->log);
    }

    // --- Reference case 4: rollback_sha checks out instead of pulling ---

    public function test_rollback_sha_checks_out_that_sha_instead_of_pulling(): void
    {
        $sha = 'aabbccddee112233445566778899aabbccddee11';
        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending, 'rollback_sha' => $sha]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner); // git.pull() is still called first in the rollback branch
        $runner->queueSuccess(); // git checkout <sha>
        $this->queueCommitCapture($runner, $sha, 'Some earlier commit');
        $runner->queueSuccess()->queueSuccess()->queueSuccess();

        $this->runHandle($dep->id);

        $updated = $dep->fresh();

        $this->assertSame(DeploymentStatus::Success, $updated->status);
        $this->assertSame($sha, $updated->commit_sha);
        $this->assertStringContainsString("=== git checkout {$sha} ===", $updated->log);
        $this->assertStringContainsString("Checked out {$sha}", $updated->log);
        $this->assertStringNotContainsString('=== git pull ===', $updated->log);

        // The checkout call itself: exact argv, and cwd == app path.
        $checkoutCall = $runner->calls[4];
        $this->assertSame(['git', 'checkout', $sha], $checkoutCall['command']);
        $this->assertSame($app->path, $checkoutCall['cwd']);
    }

    // --- Reference case 5: post-deploy pre-flight failure ---

    public function test_post_deploy_preflight_failure_blocks_exec_and_fails_deploy(): void
    {
        $app = $this->makeApp(['deploy_steps' => json_encode([['service' => 'app', 'run' => 'echo hello']])]);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess()->queueSuccess()->queueSuccess();
        $runner->queueSuccess(''); // docker compose ps --format json -> no containers

        $this->runHandle($dep->id);

        $updated = $dep->fresh();

        $this->assertSame(DeploymentStatus::Failed, $updated->status);
        // B14 (QC fix round): the assertion previously stopped just before
        // the em dash, so a mutation swapping it for a hyphen (or dropping
        // the rest of the sentence) would still pass. Full sentence, exact
        // U+2014 dash, pinned.
        $this->assertStringContainsString(
            'post-deploy: service "app" not running — check service name in bridge.yml or deploy_steps',
            $updated->log
        );

        // No "exec" command should ever have been issued.
        $execCalls = array_filter($runner->commands(), static fn (array $c) => in_array('exec', $c, true));
        $this->assertCount(0, $execCalls);
    }

    // --- Reference case 6: post-deploy step failure triggers auto-rollback ---

    public function test_post_deploy_step_failure_triggers_exactly_one_auto_rollback_deployment(): void
    {
        Bus::fake();

        $app = $this->makeApp(['deploy_steps' => json_encode([['service' => 'app', 'run' => 'migrate']])]);

        $prevSha = 'aabbccddee112233445566778899aabbccddee11';
        Deployment::create([
            'app_id' => $app->id,
            'status' => DeploymentStatus::Success,
            'commit_sha' => $prevSha,
        ]);

        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'cc112233445566778899aabbccddeeaabbccdd11', 'Migration commit');
        $runner->queueSuccess()->queueSuccess()->queueSuccess();
        $runner->queueSuccess('{"Name":"a-app-1","Service":"app","State":"running","Status":"Up","Ports":""}');
        $runner->queueFailure(1, '', "migration error\n");

        $this->runHandle($dep->id);

        $updated = $dep->fresh();
        $this->assertSame(DeploymentStatus::Failed, $updated->status);

        $rollbackDeployments = Deployment::query()->whereNotNull('rollback_sha')->get();
        $this->assertCount(1, $rollbackDeployments);
        $this->assertSame($prevSha, $rollbackDeployments->first()->rollback_sha);
        $this->assertSame(DeploymentStatus::Pending, $rollbackDeployments->first()->status);
        $this->assertNotSame($dep->id, $rollbackDeployments->first()->id);

        // B15 (QC fix round): the "Auto-rolling back to {sha}" log line was
        // never asserted anywhere — only its downstream effects (the
        // created row, the dispatch) were.
        $this->assertStringContainsString("Auto-rolling back to {$prevSha}", $updated->log);

        Bus::assertDispatched(DeployApp::class, fn (DeployApp $job) => $job->deploymentId === $rollbackDeployments->first()->id);
    }

    // --- Reference case 7: loop guard (already-a-rollback deploy) ---

    /**
     * A prior successful deployment MUST exist here — without one,
     * findLastSuccessful() returns null regardless of the guard, and this
     * test could not tell "guard correctly skipped auto-rollback" apart
     * from "guard was deleted, but there was nothing to roll back to
     * anyway." Verified by mutation: with a prior successful deployment
     * present, temporarily deleting the `if ($dep->rollback_sha) { return;
     * }` guard in DeployApp::autoRollback() makes this test fail (a second
     * rollback deployment gets created); restoring the guard brings it back
     * to green. Without the prior successful deployment, the same deleted
     * guard left this test passing — that was the actual gap this docblock
     * exists to flag.
     */
    public function test_rollback_deploy_does_not_enqueue_another_rollback_loop_guard(): void
    {
        Sleep::fake();
        Bus::fake();

        $app = $this->makeApp();
        Deployment::create([
            'app_id' => $app->id,
            'status' => DeploymentStatus::Success,
            'commit_sha' => 'ee112233445566778899aabbccddeeaabbccdd11',
        ]);

        $dep = Deployment::create([
            'app_id' => $app->id,
            'status' => DeploymentStatus::Pending,
            'rollback_sha' => 'aabbccddee112233445566778899aabbccddee11',
        ]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $runner->queueSuccess(); // checkout
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom');

        $this->runHandle($dep->id);

        $updated = $dep->fresh();
        $this->assertSame(DeploymentStatus::Failed, $updated->status);

        // Exactly the two rows created above (the prior success + this
        // failing rollback) — no third, chained rollback deployment.
        $this->assertSame(2, Deployment::query()->where('app_id', $app->id)->count());
        Bus::assertNotDispatched(DeployApp::class);
    }

    // --- Reference case 8: no prior successful deployment ---

    public function test_no_prior_successful_deployment_means_no_auto_rollback_enqueued(): void
    {
        Sleep::fake();
        Bus::fake();

        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom');

        $this->runHandle($dep->id);

        $updated = $dep->fresh();
        $this->assertSame(DeploymentStatus::Failed, $updated->status);
        // B14 (QC fix round): extended to the full sentence including the
        // exact em dash — see the pre-flight test above for the same fix.
        $this->assertStringContainsString(
            'No previous successful deployment — skipping auto-rollback',
            $updated->log
        );

        $this->assertSame(1, Deployment::query()->where('app_id', $app->id)->count());
        Bus::assertNotDispatched(DeployApp::class);
    }

    // --- Multi-app scoping: findLastSuccessful must not leak across apps ---

    /**
     * Phase 1's standing rule: any query scoped "per-app" needs a test with
     * at least TWO apps, or it can't distinguish "scoped correctly" from
     * "scoped to everything, which happens to be one app." appB's success
     * is inserted (and thus given a higher id) AFTER appA's own success, so
     * an app_id filter that was accidentally dropped from
     * Deployment::findLastSuccessful() would make this pick appB's SHA
     * instead of appA's — verified by mutation, see the phase report.
     */
    public function test_auto_rollback_only_considers_the_failing_apps_own_deployment_history(): void
    {
        Sleep::fake();
        Bus::fake();

        $appA = $this->makeApp();
        $appB = $this->makeApp();

        $appASha = 'aa112233445566778899aabbccddeeaabbccdd11';
        $appBSha = 'bb112233445566778899aabbccddeeaabbccdd11';

        Deployment::create(['app_id' => $appA->id, 'status' => DeploymentStatus::Success, 'commit_sha' => $appASha]);
        Deployment::create(['app_id' => $appB->id, 'status' => DeploymentStatus::Success, 'commit_sha' => $appBSha]);

        $dep = Deployment::create(['app_id' => $appA->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'cc112233445566778899aabbccddeeaabbccdd11', 'Broken commit');
        $runner->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom');

        $this->runHandle($dep->id);

        $rollback = Deployment::query()->where('app_id', $appA->id)->whereNotNull('rollback_sha')->first();
        $this->assertNotNull($rollback);
        $this->assertSame($appASha, $rollback->rollback_sha);

        Bus::assertDispatched(DeployApp::class, fn (DeployApp $job) => $job->deploymentId === $rollback->id);
    }

    // --- "not found" throws propagate ---

    public function test_throws_when_deployment_not_found(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Deployment 999999 not found');

        $this->runHandle(999999);
    }

    public function test_throws_when_app_not_found(): void
    {
        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        // Deployment.app_id has an FK with cascadeOnDelete(), and SQLite
        // refuses to toggle the `foreign_keys` pragma while a transaction is
        // open — RefreshDatabase wraps every test in one, so a PRAGMA
        // statement issued directly inside the test body is silently a
        // no-op (verified empirically: querying the pragma immediately
        // after "disabling" it here still reports 1, and the deployment
        // still gets cascade-deleted). Committing RefreshDatabase's
        // wrapping transaction first, toggling the pragma, deleting, then
        // re-enabling and reopening a transaction is what actually produces
        // the orphaned-deployment scenario handle() must guard against.
        //
        // That commit makes this test's App/Deployment rows permanent in
        // the shared in-memory database (RefreshDatabase's rollback-per-test
        // can no longer undo them), so the finally block below explicitly
        // deletes the orphaned deployment row again, committing THAT too —
        // otherwise it leaks into every test that runs afterward in the
        // same process (this was caught by ResetStuckDeploymentsTest's
        // exact-count assertions failing after this test ran).
        //
        // The pragma-restore + reopened transaction are themselves inside a
        // finally: if App::destroy() ever threw between the two PRAGMA
        // statements, foreign_keys would stay OFF and no transaction would
        // ever get reopened, leaving RefreshDatabase::tearDown() rolling
        // back at transaction level 0 instead of the level 1 it expects —
        // silent corruption of every test that runs after this one.
        DB::commit();
        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            App::destroy($app->id);
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
            DB::beginTransaction();
        }

        try {
            $thrown = null;
            try {
                $this->runHandle($dep->id);
            } catch (RuntimeException $e) {
                $thrown = $e;
            }

            $this->assertNotNull($thrown, 'Expected a RuntimeException for the missing App.');
            $this->assertSame("App {$app->id} not found", $thrown->getMessage());
        } finally {
            DB::commit();
            DB::table('deployments')->where('id', $dep->id)->delete();
            DB::beginTransaction();
        }
    }

    // --- Status transitions happen before the pipeline runs ---

    public function test_deployment_and_app_are_marked_running_and_deploying_before_the_pipeline_starts(): void
    {
        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();

        // Slot 0 is GitService::pull()'s `git config safe.directory *` — the
        // FIRST process this job spawns, so both status transitions must
        // already be persisted by the time this callable runs.
        //
        // QC round 2, finding 6: this queue used to be hand-rolled and
        // misaligned — 9 responses for 11 calls, with an empty answer where
        // `git rev-parse --abbrev-ref HEAD` was expected. That drove
        // GitService::pull() down its branch-MISMATCH path (branch --list,
        // checkout -b --track), which ate the two commit-capture responses,
        // left commit_sha empty, and overran the queue into the old fake's
        // silent success default. It passed while testing a different code
        // path than its name claims. Aligned queue + explicit commit_sha
        // assertion below is what keeps it honest.
        $runner->queueCallable(function () use ($dep, $app) {
            $this->assertSame(DeploymentStatus::Running, $dep->fresh()->status);
            $this->assertNotNull($dep->fresh()->started_at);
            $this->assertSame(AppStatus::Deploying, $app->fresh()->status);

            return new ProcessResult(0, '', '');
        })
            ->queueSuccess()                       // git fetch origin main
            ->queueSuccess('main')                 // git rev-parse --abbrev-ref HEAD
            ->queueSuccess('Already up to date.'); // git pull origin main
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess()->queueSuccess()->queueSuccess(); // compose pull, down, up

        $this->runHandle($dep->id);

        $this->assertSame(DeploymentStatus::Success, $dep->fresh()->status);

        // Proves the working copy was already on the target branch, so no
        // branch-checkout calls were made and the queue stayed aligned.
        $this->assertSame('aabbccddee112233445566778899aabbccddee11', $dep->fresh()->commit_sha);
        $this->assertSame('Fix the thing', $dep->fresh()->commit_message);
    }

    // --- Exact compose argv, env, idle/wall-clock timeouts ---

    public function test_compose_phase_issues_the_exact_verbatim_command_sequence_with_ssh_key_present(): void
    {
        $keyPath = tempnam(sys_get_temp_dir(), 'bridge-ssh-key-');
        try {
            config(['bridge.ssh_key_path' => $keyPath]);

            $app = $this->makeApp();
            $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

            $runner = $this->bindRunner();
            $this->queueGitPull($runner);
            $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
            $runner->queueSuccess()->queueSuccess()->queueSuccess();

            $this->runHandle($dep->id);

            $composeCalls = array_values(array_filter($runner->calls, static fn (array $c) => ($c['command'][0] ?? null) === 'docker'));
            $this->assertCount(3, $composeCalls);

            $composeFile = $app->path.'/docker-compose.yml';
            $sshCommand = "ssh -i {$keyPath} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null";

            $this->assertSame(['docker', 'compose', '-f', $composeFile, 'pull'], $composeCalls[0]['command']);
            $this->assertSame(['docker', 'compose', '-f', $composeFile, 'down'], $composeCalls[1]['command']);
            $this->assertSame(['docker', 'compose', '-f', $composeFile, 'up', '-d', '--build', '--remove-orphans'], $composeCalls[2]['command']);

            foreach ($composeCalls as $call) {
                $this->assertSame($app->path, $call['cwd']);
                $this->assertNull($call['timeout']);
                $this->assertSame(['DOCKER_PROGRESS' => 'plain', 'GIT_SSH_COMMAND' => $sshCommand], $call['env']);
            }

            $this->assertSame(60.0, $composeCalls[0]['idleTimeout']);
            $this->assertSame(300.0, $composeCalls[1]['idleTimeout']);
            $this->assertSame(300.0, $composeCalls[2]['idleTimeout']);
        } finally {
            unlink($keyPath);
        }
    }

    public function test_compose_phase_omits_git_ssh_command_when_key_file_is_missing(): void
    {
        config(['bridge.ssh_key_path' => '/definitely/does/not/exist/id_rsa']);

        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess()->queueSuccess()->queueSuccess();

        $this->runHandle($dep->id);

        $composeCalls = array_values(array_filter($runner->calls, static fn (array $c) => ($c['command'][0] ?? null) === 'docker'));
        foreach ($composeCalls as $call) {
            $this->assertSame(['DOCKER_PROGRESS' => 'plain'], $call['env']);
            $this->assertArrayNotHasKey('GIT_SSH_COMMAND', $call['env']);
        }
    }

    // --- Exact exec argv, env (no DOCKER_PROGRESS), idle timeout ---

    public function test_exec_step_issues_the_exact_verbatim_command_with_ssh_key_present_and_no_docker_progress(): void
    {
        $keyPath = tempnam(sys_get_temp_dir(), 'bridge-ssh-key-');
        try {
            config(['bridge.ssh_key_path' => $keyPath]);

            $app = $this->makeApp(['deploy_steps' => json_encode([['service' => 'app', 'run' => 'php artisan migrate --force']])]);
            $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

            $runner = $this->bindRunner();
            $this->queueGitPull($runner);
            $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
            $runner->queueSuccess()->queueSuccess()->queueSuccess();
            $runner->queueSuccess('{"Name":"a-app-1","Service":"app","State":"running","Status":"Up","Ports":""}');
            $runner->queueSuccess();

            $this->runHandle($dep->id);

            $execCall = $runner->calls[array_key_last($runner->calls)];
            $composeFile = $app->path.'/docker-compose.yml';
            $sshCommand = "ssh -i {$keyPath} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null";

            $this->assertSame(
                ['docker', 'compose', '-f', $composeFile, 'exec', '-T', 'app', 'sh', '-c', 'php artisan migrate --force'],
                $execCall['command']
            );
            $this->assertSame($app->path, $execCall['cwd']);
            $this->assertNull($execCall['timeout']);
            $this->assertSame(60.0, $execCall['idleTimeout']);
            $this->assertSame(['GIT_SSH_COMMAND' => $sshCommand], $execCall['env']);
            $this->assertArrayNotHasKey('DOCKER_PROGRESS', $execCall['env']);
        } finally {
            unlink($keyPath);
        }
    }

    public function test_exec_step_omits_git_ssh_command_when_key_file_is_missing(): void
    {
        config(['bridge.ssh_key_path' => '/definitely/does/not/exist/id_rsa']);

        $app = $this->makeApp(['deploy_steps' => json_encode([['service' => 'app', 'run' => 'echo hi']])]);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess()->queueSuccess()->queueSuccess();
        $runner->queueSuccess('{"Name":"a-app-1","Service":"app","State":"running","Status":"Up","Ports":""}');
        $runner->queueSuccess();

        $this->runHandle($dep->id);

        $execCall = $runner->calls[array_key_last($runner->calls)];
        $this->assertSame([], $execCall['env']);
    }

    /**
     * B2 + B4 (QC fix round): no existing test exercised a PASSING exec
     * step's own header line or its output. `passing onOutput: null in
     * runExecStep()` (post-deploy output never logged at all) and
     * `deleting the "--- exec {service}: {run} ---" line` both survived the
     * full suite before this test existed. Also closes B2's exec half —
     * dropping stderr specifically (as opposed to stdout) in runExecStep()'s
     * onOutput callback.
     */
    public function test_exec_step_logs_its_header_then_streams_both_stdout_and_stderr(): void
    {
        $app = $this->makeApp(['deploy_steps' => json_encode([['service' => 'app', 'run' => 'migrate']])]);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess()->queueSuccess()->queueSuccess();
        $runner->queueSuccess('{"Name":"a-app-1","Service":"app","State":"running","Status":"Up","Ports":""}');
        $runner->queueSuccess('migration stdout', 'migration stderr');

        $this->runHandle($dep->id);

        $log = $dep->fresh()->log;

        $this->assertStringContainsString('--- exec app: migrate ---', $log);
        $this->assertStringContainsString('migration stdout', $log);
        $this->assertStringContainsString('migration stderr', $log);

        // The header must precede the output it's labelling.
        $headerPos = strpos($log, '--- exec app: migrate ---');
        $stdoutPos = strpos($log, 'migration stdout');
        $this->assertLessThan($stdoutPos, $headerPos);

        $this->assertSame(DeploymentStatus::Success, $dep->fresh()->status);
    }

    // --- Retry loop: attempt labels and "Retrying in 5s..." ---

    public function test_compose_retries_up_to_three_times_with_attempt_labels_and_retry_message_then_succeeds(): void
    {
        Sleep::fake();

        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom')
            ->queueSuccess()
            ->queueSuccess() // down
            ->queueSuccess(); // up

        $this->runHandle($dep->id);

        $log = $dep->fresh()->log;

        $this->assertStringContainsString("=== docker compose pull ===\n", $log);
        $this->assertStringContainsString("=== docker compose pull (attempt 2) ===\n", $log);
        $this->assertStringContainsString("=== docker compose pull (attempt 3) ===\n", $log);
        $this->assertSame(2, substr_count($log, 'Retrying in 5s...'));
        $this->assertSame(DeploymentStatus::Success, $dep->fresh()->status);

        // B18 (QC fix round): assertSleptTimes() only pins the COUNT — a
        // mutation changing Sleep::for(5) to any other duration (e.g. a
        // stray Sleep::for(1) "for faster local testing") would still sleep
        // exactly twice and pass. assertSlept() pins the actual duration.
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds === 5.0, 2);
    }

    /**
     * Mutating `if ($attempt < 3)` to `<=` would log a third, spurious
     * "Retrying in 5s..." after the final, exhausted attempt. Asserting the
     * count is exactly 2 (not "at least 2" / "contains") is what would
     * catch that.
     */
    public function test_compose_exhausts_all_three_attempts_and_logs_exactly_two_retry_messages(): void
    {
        Sleep::fake();

        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom');

        $this->runHandle($dep->id);

        $log = $dep->fresh()->log;

        $this->assertSame(2, substr_count($log, 'Retrying in 5s...'));
        $this->assertStringContainsString("\nERROR: docker compose pull failed after 3 attempts\n", $log);
        // B18 (QC fix round): assertSleptTimes() only pins the COUNT — a
        // mutation changing Sleep::for(5) to any other duration (e.g. a
        // stray Sleep::for(1) "for faster local testing") would still sleep
        // exactly twice and pass. assertSlept() pins the actual duration.
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds === 5.0, 2);
    }

    // --- B11 (QC fix round): a spawn failure (ProcessRunner::run() itself
    // throwing, e.g. the docker binary is missing) must be caught and
    // treated as a normal exit-code-1 failure, going through the SAME
    // retry/no-retry rules as any other non-zero exit — never escaping
    // straight to the outer catch after a single attempt. Ported from
    // deployApp.ts's spawnWithStall() proc.on('error') handler, which logs
    // and resolve(1)s rather than rejecting the promise.

    public function test_compose_spawn_failure_is_caught_and_goes_through_all_three_retry_attempts(): void
    {
        Sleep::fake();

        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueThrowable(new RuntimeException('spawn ENOENT'))
            ->queueThrowable(new RuntimeException('spawn ENOENT'))
            ->queueThrowable(new RuntimeException('spawn ENOENT'));

        $this->runHandle($dep->id);

        $updated = $dep->fresh();
        $log = $updated->log;

        $this->assertSame(DeploymentStatus::Failed, $updated->status);
        $this->assertSame(3, substr_count($log, "\nERROR: spawn ENOENT\n"));
        $this->assertStringContainsString('=== docker compose pull ===', $log);
        $this->assertStringContainsString('=== docker compose pull (attempt 2) ===', $log);
        $this->assertStringContainsString('=== docker compose pull (attempt 3) ===', $log);
        $this->assertStringContainsString("\nERROR: docker compose pull failed after 3 attempts\n", $log);

        // All three attempts issued the compose "pull" argv — a spawn
        // failure must never skip straight to the outer catch after one.
        $composeCalls = array_values(array_filter($runner->calls, static fn (array $c) => ($c['command'][0] ?? null) === 'docker'));
        $this->assertCount(3, $composeCalls);
        foreach ($composeCalls as $call) {
            $this->assertSame('pull', $call['command'][4]);
        }
    }

    public function test_exec_step_spawn_failure_is_caught_and_reported_as_a_step_failure_with_no_retry(): void
    {
        $app = $this->makeApp(['deploy_steps' => json_encode([['service' => 'app', 'run' => 'echo hi']])]);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess()->queueSuccess()->queueSuccess();
        $runner->queueSuccess('{"Name":"a-app-1","Service":"app","State":"running","Status":"Up","Ports":""}');
        $runner->queueThrowable(new RuntimeException('spawn ENOENT'));

        $this->runHandle($dep->id);

        $updated = $dep->fresh();
        $this->assertSame(DeploymentStatus::Failed, $updated->status);
        // QC round 2, finding 4: asserting the bare "\nERROR: spawn ENOENT\n"
        // substring passed for the WRONG reason — the leading \n came from
        // the preceding exec header and the trailing \n from the outer
        // catch's own "\nERROR: post-deploy step failed…". Stripping
        // runExecStep()'s framing entirely left that substring intact, so
        // the mutation survived. Anchoring the segment to the exec header
        // that immediately precedes it is what actually pins the framing.
        $this->assertStringContainsString(
            "--- exec app: echo hi ---\n\nERROR: spawn ENOENT\n",
            $updated->log
        );
        $this->assertStringContainsString('post-deploy step failed: app: echo hi', $updated->log);

        // Post-deploy steps never retry — exactly one exec attempt.
        $execCalls = array_filter($runner->commands(), static fn (array $c) => in_array('exec', $c, true));
        $this->assertCount(1, $execCalls);
    }

    // --- Stall path: exact message, treated as retryable, not thrown ---

    public function test_compose_stall_on_pull_logs_the_exact_60_second_message_and_is_retried_not_thrown(): void
    {
        Sleep::fake();

        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueTimeout('partial output before stall')
            ->queueSuccess() // pull, attempt 2
            ->queueSuccess() // down
            ->queueSuccess(); // up

        $this->runHandle($dep->id);

        $log = $dep->fresh()->log;

        $this->assertStringContainsString("\nERROR: process stalled — no output for 60s, killed.\n", $log);
        $this->assertStringContainsString('Retrying in 5s...', $log);
        $this->assertStringNotContainsString('docker compose pull failed after 3 attempts', $log);
        $this->assertSame(DeploymentStatus::Success, $dep->fresh()->status);
    }

    public function test_compose_stall_on_up_logs_the_exact_300_second_message(): void
    {
        Sleep::fake();

        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess() // pull
            ->queueSuccess() // down
            ->queueTimeout() // up, attempt 1: stall
            ->queueSuccess(); // up, attempt 2

        $this->runHandle($dep->id);

        $this->assertStringContainsString("\nERROR: process stalled — no output for 300s, killed.\n", $dep->fresh()->log);
    }

    /**
     * QC round 2, finding 9: this was named `..._and_is_retried`, which
     * contradicts both the code and its sibling above — exec steps have no
     * retry (reference/src/jobs/deployApp.ts:147-153) and this test queues
     * exactly one exec response. The exec-call count below now pins that.
     */
    public function test_exec_step_stall_logs_the_exact_60_second_message_and_is_not_retried(): void
    {
        $app = $this->makeApp(['deploy_steps' => json_encode([['service' => 'app', 'run' => 'echo hi']])]);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess()->queueSuccess()->queueSuccess();
        $runner->queueSuccess('{"Name":"a-app-1","Service":"app","State":"running","Status":"Up","Ports":""}');
        $runner->queueTimeout();

        $this->runHandle($dep->id);

        $log = $dep->fresh()->log;

        $this->assertStringContainsString("\nERROR: process stalled — no output for 60s, killed.\n", $log);
        $this->assertStringContainsString('post-deploy step failed: app: echo hi', $log);
        $this->assertSame(DeploymentStatus::Failed, $dep->fresh()->status);

        $execCalls = array_filter($runner->commands(), static fn (array $c) => in_array('exec', $c, true));
        $this->assertCount(1, $execCalls);
    }

    // --- B3 (QC fix round): the post-deploy header wording is entirely
    // unpinned before this — forcing $plural = 's' unconditionally
    // survives, and deleting the whole appendLog() line survives too.

    public function test_post_deploy_header_uses_singular_wording_for_one_step(): void
    {
        $app = $this->makeApp(['deploy_steps' => json_encode([['service' => 'app', 'run' => 'echo hi']])]);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess()->queueSuccess()->queueSuccess();
        $runner->queueSuccess('{"Name":"a-app-1","Service":"app","State":"running","Status":"Up","Ports":""}');
        $runner->queueSuccess();

        $this->runHandle($dep->id);

        $this->assertStringContainsString('=== post-deploy (1 step, source: ui) ===', $dep->fresh()->log);
    }

    public function test_post_deploy_header_uses_plural_wording_for_multiple_steps(): void
    {
        $app = $this->makeApp(['deploy_steps' => json_encode([
            ['service' => 'web', 'run' => 'echo one'],
            ['service' => 'worker', 'run' => 'echo two'],
        ])]);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess()->queueSuccess()->queueSuccess();
        $runner->queueSuccess(
            '{"Name":"a-web-1","Service":"web","State":"running","Status":"Up","Ports":""}'."\n"
            .'{"Name":"a-worker-1","Service":"worker","State":"running","Status":"Up","Ports":""}'
        );
        $runner->queueSuccess()->queueSuccess();

        $this->runHandle($dep->id);

        $this->assertStringContainsString('=== post-deploy (2 steps, source: ui) ===', $dep->fresh()->log);
    }

    public function test_post_deploy_header_reports_repo_source_when_bridge_yml_is_present(): void
    {
        $app = $this->makeAppWithBridgeYml("post_deploy:\n  - service: app\n    run: php artisan migrate --force\n");
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess()->queueSuccess()->queueSuccess();
        $runner->queueSuccess('{"Name":"a-app-1","Service":"app","State":"running","Status":"Up","Ports":""}');
        $runner->queueSuccess();

        $this->runHandle($dep->id);

        $this->assertStringContainsString('=== post-deploy (1 step, source: repo) ===', $dep->fresh()->log);
    }

    // --- Pre-flight completes for ALL steps before ANY exec runs ---

    /**
     * Two steps: the first ('web') IS running, the second ('worker') is
     * not. If pre-flight were folded into the exec loop instead of run as
     * its own pass first, step 1's exec would fire before step 2's check
     * ever ran. Asserting zero exec calls proves neither ran.
     */
    public function test_preflight_checks_all_steps_before_any_exec_runs(): void
    {
        $app = $this->makeApp(['deploy_steps' => json_encode([
            ['service' => 'web', 'run' => 'echo one'],
            ['service' => 'worker', 'run' => 'echo two'],
        ])]);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess()->queueSuccess()->queueSuccess();
        $runner->queueSuccess('{"Name":"a-web-1","Service":"web","State":"running","Status":"Up","Ports":""}');

        $this->runHandle($dep->id);

        $log = $dep->fresh()->log;
        // B14 (QC fix round): full sentence, exact em dash.
        $this->assertStringContainsString(
            'post-deploy: service "worker" not running — check service name in bridge.yml or deploy_steps',
            $log
        );
        $this->assertStringNotContainsString('exec web', $log);
        $this->assertStringNotContainsString('exec worker', $log);

        $execCalls = array_filter($runner->commands(), static fn (array $c) => in_array('exec', $c, true));
        $this->assertCount(0, $execCalls);
    }

    // --- B5 (QC fix round): Slack must actually be sent on both paths.
    // Deleting `$this->notify($dep)` survived on BOTH the success path AND
    // the catch path before these existed — the earlier "fire-and-forget"
    // test below only proved a notifier FAILURE doesn't block the deploy,
    // never that a notification is sent in the first place.

    public function test_sends_a_slack_notification_on_a_successful_deploy(): void
    {
        Setting::setValue('slack_webhook_url', 'https://hooks.example.test/services/success');
        Http::fake();

        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess()->queueSuccess()->queueSuccess();

        $this->runHandle($dep->id);

        $this->assertSame(DeploymentStatus::Success, $dep->fresh()->status);
        Http::assertSent(function ($request) {
            $payload = $request->data();
            $text = $payload['attachments'][0]['blocks'][0]['text']['text'] ?? '';

            return $request->url() === 'https://hooks.example.test/services/success'
                && str_contains($text, 'success');
        });
    }

    public function test_sends_a_slack_notification_on_a_failed_deploy(): void
    {
        Sleep::fake();
        Bus::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.example.test/services/failure');
        Http::fake();

        $app = $this->makeApp();
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom');

        $this->runHandle($dep->id);

        $this->assertSame(DeploymentStatus::Failed, $dep->fresh()->status);
        Http::assertSent(function ($request) {
            $payload = $request->data();
            $text = $payload['attachments'][0]['blocks'][0]['text']['text'] ?? '';

            return $request->url() === 'https://hooks.example.test/services/failure'
                && str_contains($text, 'failed');
        });
    }

    /**
     * QC round 2, finding 7: swapping `$this->notify($dep)` and
     * `$this->autoRollback($dep)` in handle()'s catch block survives the
     * suite — nothing pins their order, although the port's order matches
     * reference/src/jobs/deployApp.ts:166 then :169.
     *
     * The difference is observable: SlackNotifier attaches the last 20 log
     * lines on failure only, so a rollback appended BEFORE the notification
     * leaks its "Auto-rolling back to <sha>" line into the Slack payload of
     * the deployment that just failed.
     */
    public function test_slack_is_notified_before_the_auto_rollback_line_is_appended_to_the_log(): void
    {
        Sleep::fake();
        Bus::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.example.test/services/order');
        Http::fake();

        $app = $this->makeApp();
        $prevSha = 'ff112233445566778899aabbccddeeaabbccdd11';
        Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Success, 'commit_sha' => $prevSha]);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Broken commit');
        $runner->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom');

        $this->runHandle($dep->id);

        // The auto-rollback really did happen — without this the payload
        // assertion below would pass vacuously.
        $this->assertStringContainsString("Auto-rolling back to {$prevSha}", $dep->fresh()->log);
        Bus::assertDispatched(DeployApp::class);

        Http::assertSent(function ($request) use ($prevSha) {
            $logBlock = $request->data()['attachments'][0]['blocks'][1]['text']['text'] ?? '';

            return str_contains($logBlock, 'docker compose pull failed after 3 attempts')
                && ! str_contains($logBlock, "Auto-rolling back to {$prevSha}");
        });
    }

    // --- Slack is fire-and-forget: never blocks the deploy or the rollback ---

    public function test_slack_notification_failure_does_not_block_deploy_or_auto_rollback(): void
    {
        Sleep::fake();
        Bus::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.example.test/services/x');
        Http::fake(function () {
            throw new ConnectionException('slack is down');
        });

        $app = $this->makeApp();
        $prevSha = 'aabbccddee112233445566778899aabbccddee11';
        Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Success, 'commit_sha' => $prevSha]);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'cc112233445566778899aabbccddeeaabbccdd11', 'Broken commit');
        $runner->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom');

        $this->runHandle($dep->id);

        $this->assertSame(DeploymentStatus::Failed, $dep->fresh()->status);
        Bus::assertDispatched(DeployApp::class);
    }

    // --- QC fix round 2 ---

    /**
     * B12: $tries and $timeout have never been asserted directly. $timeout
     * = 0 is the one that matters most in production — Laravel's queue
     * worker enforces its own 60s default job timeout on top of whatever
     * the job property says, and a 40-minute build needs this to disable
     * that enforcement entirely (see docs/porting-notes.md, Phase 2's
     * "Forward risks for Phase 3" and Phase 3's own notes for Phase 7).
     */
    public function test_job_tries_and_timeout_properties_are_pinned(): void
    {
        $job = new DeployApp(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame(0, $job->timeout);
    }

    /**
     * B8: runPostDeployPhase()'s pre-flight was only ever exercised against
     * two container states — "running" (passes) and "absent from `docker
     * compose ps` output entirely" (fails, via the pre-flight and
     * post-deploy-step-failure tests above). Neither distinguishes
     * `($container['state'] ?? null) === 'running'` from the looser
     * `($container['state'] ?? null) !== null` — a container that IS
     * present but in some other state (crashed, restarting, exited)
     * satisfies the loose check and would be wrongly treated as running.
     */
    public function test_preflight_fails_when_the_matching_service_is_present_but_not_running(): void
    {
        $app = $this->makeApp(['deploy_steps' => json_encode([['service' => 'app', 'run' => 'echo hi']])]);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess()->queueSuccess()->queueSuccess();
        $runner->queueSuccess('{"Name":"a-app-1","Service":"app","State":"exited","Status":"Exited (1)","Ports":""}');

        $this->runHandle($dep->id);

        $updated = $dep->fresh();
        $this->assertSame(DeploymentStatus::Failed, $updated->status);
        $this->assertStringContainsString(
            'post-deploy: service "app" not running — check service name in bridge.yml or deploy_steps',
            $updated->log
        );

        $execCalls = array_filter($runner->commands(), static fn (array $c) => in_array('exec', $c, true));
        $this->assertCount(0, $execCalls);
    }

    /**
     * B9: `Deployment::findLastSuccessful()` already filters
     * `whereNotNull('commit_sha')` at the query level, so a prior
     * deployment with a genuinely NULL commit_sha never becomes $previous
     * in the first place — that alone can't discriminate
     * `if ($previous && $previous->commit_sha)` from `if ($previous)` in
     * DeployApp::autoRollback(), since $previous is null under both. An
     * empty-STRING commit_sha passes the NOT NULL filter (findLastSuccessful()
     * DOES return the row) while still being falsy in PHP, which is what
     * actually exercises the second half of the guard.
     */
    public function test_auto_rollback_guard_requires_a_truthy_commit_sha_not_just_a_non_null_previous_deployment(): void
    {
        Sleep::fake();
        Bus::fake();

        $app = $this->makeApp();
        Deployment::create([
            'app_id' => $app->id,
            'status' => DeploymentStatus::Success,
            'commit_sha' => '',
        ]);

        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom');

        $this->runHandle($dep->id);

        $updated = $dep->fresh();
        $this->assertSame(DeploymentStatus::Failed, $updated->status);
        $this->assertStringContainsString(
            'No previous successful deployment — skipping auto-rollback',
            $updated->log
        );

        $this->assertSame(0, Deployment::query()->whereNotNull('rollback_sha')->count());
        Bus::assertNotDispatched(DeployApp::class);
    }

    /**
     * B10: autoRollback() calls
     * `Deployment::findLastSuccessful($dep->app_id, $dep->id)` — the
     * beforeId bound must be the FAILING deployment's own id, not some
     * arbitrarily widened bound. A successful deployment created AFTER the
     * failing one (higher id) must never be selected: it didn't exist when
     * this deploy started, so it can't be what the app was last known-good
     * at. Distinct from
     * test_auto_rollback_only_considers_the_failing_apps_own_deployment_history
     * above, which pins the app_id filter, not the id bound.
     */
    public function test_auto_rollback_never_selects_a_successful_deployment_created_after_the_failing_one(): void
    {
        Sleep::fake();
        Bus::fake();

        $app = $this->makeApp();

        // Created FIRST, so it gets the LOWER id — this is the deploy that fails.
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        // Created AFTER $dep, so it gets a HIGHER id, despite being
        // "successful" — under correct `id < beforeId` semantics this can
        // never be a rollback candidate for $dep.
        Deployment::create([
            'app_id' => $app->id,
            'status' => DeploymentStatus::Success,
            'commit_sha' => 'ff112233445566778899aabbccddeeaabbccdd11',
        ]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom')
            ->queueFailure(1, '', 'boom');

        $this->runHandle($dep->id);

        $updated = $dep->fresh();
        $this->assertSame(DeploymentStatus::Failed, $updated->status);

        $this->assertSame(0, Deployment::query()->whereNotNull('rollback_sha')->count());
        Bus::assertNotDispatched(DeployApp::class);
    }

    /**
     * B13: DeploySteps::resolve() is documented (docs/porting-notes.md,
     * Phase 1's "deploy_steps serialisation decision") to THROW on
     * malformed deploy_steps JSON, mirroring the reference's
     * `post-deploy: deploy_steps JSON parse error`. If DeployApp ever
     * wrapped that call in a try/catch that swallowed the exception and
     * fell back to zero steps, a broken deploy_steps column would silently
     * report the deploy SUCCESSFUL instead of failing loudly.
     */
    public function test_malformed_deploy_steps_json_fails_the_deploy_instead_of_silently_running_zero_steps(): void
    {
        $app = $this->makeApp(['deploy_steps' => '{not json']);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess()->queueSuccess()->queueSuccess();

        $this->runHandle($dep->id);

        $updated = $dep->fresh();
        $this->assertSame(DeploymentStatus::Failed, $updated->status);
        $this->assertStringContainsString('post-deploy: deploy_steps JSON parse error', $updated->log);
    }

    /**
     * B6-full: nothing previously proved the App lookup in handle() (or the
     * two other app-typed call sites it feeds — DeploySteps::resolve($app)
     * and ContainerStatus::forWorkDir($app->path)) is actually scoped to
     * THIS deployment's app. Every other test in this file creates only one
     * app, so `App::find($dep->app_id)` and, say,
     * `App::query()->orderByDesc('id')->first()` are indistinguishable —
     * both happen to return the only row in the table. appB is deliberately
     * the MIDDLE id of three apps (not the highest, not the lowest) so that
     * BOTH `orderByDesc('id')->first()` and `orderBy('id')->first()`
     * mutants resolve to the WRONG app, and each app gets its own distinct
     * path AND deploy_steps so a wrong-app substitution is observable both
     * in the argv's cwd and in which service/command the post-deploy exec
     * step actually runs.
     */
    public function test_app_lookup_and_every_downstream_call_are_scoped_to_the_deployments_own_app(): void
    {
        $appA = $this->makeApp(['deploy_steps' => json_encode([['service' => 'a-svc', 'run' => 'echo app-a']])]);
        $appB = $this->makeApp(['deploy_steps' => json_encode([['service' => 'b-svc', 'run' => 'echo app-b']])]);
        $appC = $this->makeApp(['deploy_steps' => json_encode([['service' => 'c-svc', 'run' => 'echo app-c']])]);

        $this->assertTrue($appA->id < $appB->id && $appB->id < $appC->id);

        $dep = Deployment::create(['app_id' => $appB->id, 'status' => DeploymentStatus::Pending]);

        $runner = $this->bindRunner();
        $this->queueGitPull($runner);
        $this->queueCommitCapture($runner, 'aabbccddee112233445566778899aabbccddee11', 'Fix the thing');
        $runner->queueSuccess()->queueSuccess()->queueSuccess(); // compose pull/down/up
        $runner->queueSuccess('{"Name":"b-app-1","Service":"b-svc","State":"running","Status":"Up","Ports":""}'); // docker compose ps
        $runner->queueSuccess(); // exec b-svc

        $this->runHandle($dep->id);

        $updated = $dep->fresh();
        $this->assertSame(DeploymentStatus::Success, $updated->status);
        $this->assertSame(AppStatus::Success, $appB->fresh()->status);

        // Every process invocation the job made — git, compose, docker
        // compose ps, exec — ran with appB's path as cwd, never appA's,
        // appC's, or a hardcoded path.
        $this->assertNotEmpty($runner->calls);
        foreach ($runner->calls as $call) {
            $this->assertSame($appB->path, $call['cwd']);
        }

        // The post-deploy exec used appB's OWN deploy_steps — proves the
        // DeploySteps::resolve($app) call site is scoped too, not just the
        // compose phase.
        $execCalls = array_values(array_filter($runner->commands(), static fn (array $c) => in_array('exec', $c, true)));
        $this->assertCount(1, $execCalls);
        $this->assertContains('b-svc', $execCalls[0]);
        $this->assertContains('echo app-b', $execCalls[0]);

        // appA and appC are completely untouched: no status change, no
        // deployment rows of their own.
        $this->assertSame(AppStatus::Idle, $appA->fresh()->status);
        $this->assertSame(AppStatus::Idle, $appC->fresh()->status);
        $this->assertSame(0, Deployment::query()->where('app_id', $appA->id)->count());
        $this->assertSame(0, Deployment::query()->where('app_id', $appC->id)->count());
    }
}
