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
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class DeployApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 0;

    /** @var callable */
    private $composeRunner;

    private ?Process $activeProcess = null;

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
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->activeProcess?->stop(0);
            exit(1);
        });

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

    public function failed(\Throwable $exception): void
    {
        if ($this->deployment->status === DeploymentStatus::Running) {
            $this->deployment->update([
                'status'      => DeploymentStatus::Failed,
                'finished_at' => now(),
            ]);
            $this->deployment->app?->update(['status' => AppStatus::Failed]);
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
            $env    = ['DOCKER_PROGRESS' => 'plain'];
            if (file_exists((string) $sshKey)) {
                $env['GIT_SSH_COMMAND'] = "ssh -i {$sshKey} -o StrictHostKeyChecking=no";
            }

            $composePath  = $workDir . '/docker-compose.yml';
            $cmd          = 'docker-compose -f ' . escapeshellarg($composePath) . ' ' . $subCmd;
            $stallTimeout = str_starts_with($subCmd, 'pull') ? 60 : 300;

            $process = Process::fromShellCommandline($cmd, $workDir, $env);
            $process->setTimeout(null);
            $process->setIdleTimeout($stallTimeout);

            $this->activeProcess = $process;

            try {
                $process->run(fn(string $type, string $buffer) => $onOutput($buffer));
            } catch (ProcessTimedOutException) {
                $onOutput("\nERROR: process stalled — no output for {$stallTimeout}s, killed.\n");
                return 1;
            } finally {
                $this->activeProcess = null;
            }

            return $process->getExitCode() ?? 1;
        };
    }
}
