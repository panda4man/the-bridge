<?php

namespace App\Services;

use App\Services\Process\ProcessResult;
use App\Services\Process\ProcessRunner;
use RuntimeException;

/**
 * Ported from reference/src/services/gitService.ts.
 *
 * Every git invocation here is verbatim from the reference, including the
 * `-c safe.directory=*` flags and the branch-checkout branching inside
 * pull() — do not "improve" the command construction, the flags are
 * load-bearing in the container (the repo dirs are bind-mounted from the
 * host and owned by a different uid than the one running git inside the
 * container, which is exactly what `safe.directory` papers over).
 *
 * The reference used simple-git, a wrapper over the `git` CLI. This port
 * shells out directly via the injected ProcessRunner (Symfony\Process in
 * production, a FakeProcessRunner in tests) so no real git remote or network
 * is needed to test it — tests assert on the literal argv captured by the
 * fake.
 */
final class GitService
{
    private string $sshKeyPath;

    public function __construct(
        private readonly ProcessRunner $runner,
        ?string $sshKeyPath = null,
    ) {
        $this->sshKeyPath = $sshKeyPath ?? (string) config('bridge.ssh_key_path');
    }

    public function clone(string $repoUrl, string $targetPath, string $branch): string
    {
        $this->mustRun(
            ['git', 'clone', '--branch', $branch, '-c', 'safe.directory=*', $repoUrl, $targetPath],
            null,
        );

        return "Cloned {$repoUrl} into {$targetPath}";
    }

    /**
     * @return list<string> Branch names (with the `refs/heads/` prefix stripped).
     */
    public function lsRemote(string $repoUrl): array
    {
        $result = $this->mustRun(['git', 'ls-remote', '--heads', $repoUrl], null);

        $branches = [];
        foreach (preg_split('/\r?\n/', $result->output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $ref = explode("\t", $line)[1] ?? '';
            if (str_starts_with($ref, 'refs/heads/')) {
                $branches[] = substr($ref, strlen('refs/heads/'));
            }
        }

        return $branches;
    }

    /**
     * Check out a specific commit SHA in an already-cloned repo. Used by
     * Phase 3's rollback path (`deployApp.ts`'s `execFileSync('git',
     * ['checkout', dep.rollback_sha], { cwd: app.path })`) after `pull()` has
     * already brought the working copy up to date. Throws (via mustRun) on a
     * non-zero exit, exactly like the reference's execFileSync.
     */
    public function checkout(string $repoPath, string $sha): void
    {
        $this->mustRun(['git', 'checkout', $sha], $repoPath);
    }

    /**
     * `git rev-parse HEAD`, trimmed. Ported from deployApp.ts's
     * `execSync('git rev-parse HEAD', { cwd: app.path })`.
     */
    public function revParseHead(string $repoPath): string
    {
        return trim($this->mustRun(['git', 'rev-parse', 'HEAD'], $repoPath)->output);
    }

    /**
     * `git log -1 --format=%s`, trimmed. Ported from deployApp.ts's
     * `execSync('git log -1 --format=%s', { cwd: app.path })`.
     */
    public function lastCommitSubject(string $repoPath): string
    {
        return trim($this->mustRun(['git', 'log', '-1', '--format=%s'], $repoPath)->output);
    }

    public function pull(string $repoPath, string $branch): string
    {
        // --global + cwd=null: writes straight to the global gitconfig with no
        // repo discovery, so it succeeds even when $repoPath is dubiously
        // owned (local-scope safe.directory is ignored by git on purpose, and
        // writing local scope requires discovering the repo first — which is
        // exactly the check we're trying to bypass). --replace-all keeps this
        // idempotent across repeated pulls instead of appending duplicates.
        $this->runner->run(['git', 'config', '--global', '--replace-all', 'safe.directory', '*'], null, $this->envFor());

        $log = [];

        $fetchOut = $this->mustRun(['git', 'fetch', 'origin', $branch], $repoPath);
        if (trim($fetchOut->output) !== '') {
            $log[] = trim($fetchOut->output);
        }

        $currentBranch = trim($this->mustRun(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $repoPath)->output);

        if ($currentBranch !== $branch) {
            $localBranches = $this->mustRun(['git', 'branch', '--list', $branch], $repoPath);
            $checkoutOut = trim($localBranches->output) !== ''
                ? $this->mustRun(['git', 'checkout', $branch], $repoPath)
                : $this->mustRun(['git', 'checkout', '-b', $branch, '--track', "origin/{$branch}"], $repoPath);

            if (trim($checkoutOut->output) !== '') {
                $log[] = trim($checkoutOut->output);
            }
        }

        $pullOut = $this->mustRun(['git', 'pull', 'origin', $branch], $repoPath);
        $log[] = trim($pullOut->output) !== '' ? trim($pullOut->output) : "Pulled {$branch} in {$repoPath}";

        return implode("\n", $log);
    }

    /**
     * simple-git ignores `env` passed to the factory, so the SSH command must
     * be applied via .env() — which is guarded behind allowUnsafeSshCommand.
     * The single-key-set-here form merges with the inherited environment
     * (see App\Services\Process\ProcessRunner's doc), preserving PATH etc.
     *
     * GIT_SSH_COMMAND is set ONLY when the key file actually exists —
     * unconditionally setting it breaks public-repo clones on hosts with no
     * key configured.
     *
     * @return array<string, string>
     */
    private function envFor(): array
    {
        if ($this->sshKeyPath !== '' && file_exists($this->sshKeyPath)) {
            return [
                'GIT_SSH_COMMAND' => "ssh -i {$this->sshKeyPath} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null",
            ];
        }

        return [];
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRun(array $command, ?string $cwd): ProcessResult
    {
        $result = $this->runner->run($command, $cwd, $this->envFor());

        if (! $result->successful()) {
            $message = trim($result->errorOutput) !== '' ? trim($result->errorOutput) : trim($result->output);
            throw new RuntimeException($message !== '' ? $message : implode(' ', $command).' failed');
        }

        return $result;
    }
}
