<?php

namespace App\Jobs;

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Services\GitService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class DeployApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var callable */
    private $composeRunner;

    public function __construct(
        private readonly Deployment $deployment,
        ?callable $composeRunner = null
    ) {
        $this->composeRunner = $composeRunner ?? $this->defaultComposeRunner();
    }

    public function __serialize(): array
    {
        return ['deployment' => $this->deployment];
    }

    public function __unserialize(array $data): void
    {
        $this->deployment    = $data['deployment'];
        $this->composeRunner = $this->defaultComposeRunner();
    }

    public function handle(): void
    {
        $deployment = $this->deployment;
        $app        = $deployment->app;

        $deployment->update(['status' => DeploymentStatus::Running, 'started_at' => now()]);
        $app->update(['status' => AppStatus::Deploying]);

        try {
            $git = new GitService();
            $this->appendLog($deployment, "=== git pull ===\n");
            $output = $git->pull($app->path, $app->branch);
            $this->appendLog($deployment, $output . "\n");

            foreach (['pull', 'up -d --build --remove-orphans'] as $subCmd) {
                $attempts = 3;
                $success  = false;
                for ($i = 1; $i <= $attempts; $i++) {
                    $this->appendLog($deployment, "=== docker compose {$subCmd}" . ($i > 1 ? " (attempt {$i})" : '') . " ===\n");
                    $exit = ($this->composeRunner)($subCmd, $app->path, fn (string $chunk) => $this->appendLog($deployment, $chunk));
                    if ($exit === 0) {
                        $success = true;
                        break;
                    }
                    if ($i < $attempts) {
                        $this->appendLog($deployment, "Retrying in 5s...\n");
                        sleep(5);
                    }
                }
                if (!$success) {
                    throw new \RuntimeException("docker compose {$subCmd} failed after {$attempts} attempts");
                }
            }

            $deployment->update(['status' => DeploymentStatus::Success, 'finished_at' => now()]);
            $app->update(['status' => AppStatus::Success]);

        } catch (\Throwable $e) {
            $this->appendLog($deployment, "\nERROR: " . $e->getMessage() . "\n");
            $deployment->update(['status' => DeploymentStatus::Failed, 'finished_at' => now()]);
            $app->update(['status' => AppStatus::Failed]);
        }
    }

    private function appendLog(Deployment $deployment, string $chunk): void
    {
        DB::statement(
            "UPDATE deployments SET log = COALESCE(log, '') || ? WHERE id = ?",
            [$this->stripAnsi($chunk), $deployment->id]
        );
    }

    private function stripAnsi(string $text): string
    {
        return preg_replace('/\x1b\[[0-9;]*[A-Za-z]|\r/', '', $text);
    }

    private function defaultComposeRunner(): callable
    {
        return function (string $subCmd, string $workDir, callable $onOutput): int {
            $sshKey = config('bridge.ssh_key_path');
            $sshEnv = 'DOCKER_PROGRESS=plain ';
            if (file_exists((string) $sshKey)) {
                $sshEnv .= 'GIT_SSH_COMMAND=' . escapeshellarg("ssh -i {$sshKey} -o StrictHostKeyChecking=no") . ' ';
            }

            $composePath = escapeshellarg("{$workDir}/docker-compose.yml");
            $cmd         = "{$sshEnv}docker-compose -f {$composePath} {$subCmd}";

            $spec  = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc  = proc_open($cmd, $spec, $pipes, $workDir);

            if (!is_resource($proc)) {
                $onOutput("Failed to start: {$cmd}\n");
                return 1;
            }

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            // pull = network stream, should have steady output; build = can go quiet for minutes
            $stallTimeout = str_starts_with($subCmd, 'pull') ? 60 : 300;
            $lastOutput   = time();

            while (true) {
                $out = fread($pipes[1], 4096);
                $err = fread($pipes[2], 4096);
                if ($out) { $onOutput($out); $lastOutput = time(); }
                if ($err) { $onOutput($err); $lastOutput = time(); }
                if (feof($pipes[1]) && feof($pipes[2])) break;
                if (time() - $lastOutput > $stallTimeout) {
                    proc_terminate($proc);
                    $onOutput("\nERROR: process stalled — no output for {$stallTimeout}s, killed.\n");
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($proc);
                    return 1;
                }
                usleep(50000);
            }

            fclose($pipes[1]);
            fclose($pipes[2]);

            return proc_close($proc);
        };
    }
}
