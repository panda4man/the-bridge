<?php

namespace App\Services;

class GitService
{
    private ?string $sshKeyPath;

    public function __construct(?string $sshKeyPath = null)
    {
        $this->sshKeyPath = $sshKeyPath ?? config('bridge.ssh_key_path');
    }

    public function clone(string $repoUrl, string $targetPath, string $branch): string
    {
        $cmd = $this->withSsh(
            'git -c safe.directory=\'*\' clone --branch ' . escapeshellarg($branch)
            . ' ' . escapeshellarg($repoUrl)
            . ' ' . escapeshellarg($targetPath)
        );
        return $this->run($cmd);
    }

    public function pull(string $repoPath, string $branch): string
    {
        $cmd = $this->withSsh(
            'git -c safe.directory=\'*\' -C ' . escapeshellarg($repoPath)
            . ' pull origin ' . escapeshellarg($branch)
        );
        return $this->run($cmd);
    }

    private function withSsh(string $gitCmd): string
    {
        if ($this->sshKeyPath && file_exists($this->sshKeyPath)) {
            $sshEnv = 'GIT_SSH_COMMAND=' . escapeshellarg(
                'ssh -i ' . escapeshellarg($this->sshKeyPath) . ' -o StrictHostKeyChecking=no'
            );
            return "{$sshEnv} {$gitCmd}";
        }
        return $gitCmd;
    }

    private function run(string $cmd): string
    {
        $output   = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException(implode("\n", $output));
        }

        return implode("\n", $output);
    }
}
