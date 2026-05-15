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

            foreach (['pull', 'up -d --build'] as $subCmd) {
                $this->appendLog($deployment, "=== docker compose {$subCmd} ===\n");
                $exit = ($this->composeRunner)($subCmd, $app->path, fn (string $chunk) => $this->appendLog($deployment, $chunk));
                if ($exit !== 0) {
                    throw new \RuntimeException("docker compose {$subCmd} exited with code {$exit}");
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
            [$chunk, $deployment->id]
        );
    }

    private function defaultComposeRunner(): callable
    {
        return function (string $subCmd, string $workDir, callable $onOutput): int {
            $sshKey = config('bridge.ssh_key_path');
            $sshEnv = '';
            if (file_exists((string) $sshKey)) {
                $sshEnv = 'GIT_SSH_COMMAND=' . escapeshellarg("ssh -i {$sshKey} -o StrictHostKeyChecking=no") . ' ';
            }

            $composePath = escapeshellarg("{$workDir}/docker-compose.yml");
            $cmd         = "{$sshEnv}docker compose -f {$composePath} {$subCmd}";

            $spec  = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc  = proc_open($cmd, $spec, $pipes, $workDir);

            if (!is_resource($proc)) {
                $onOutput("Failed to start: {$cmd}\n");
                return 1;
            }

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            while (true) {
                $out = fread($pipes[1], 4096);
                $err = fread($pipes[2], 4096);
                if ($out) $onOutput($out);
                if ($err) $onOutput($err);
                if (feof($pipes[1]) && feof($pipes[2])) break;
                usleep(50000);
            }

            fclose($pipes[1]);
            fclose($pipes[2]);

            return proc_close($proc);
        };
    }
}
