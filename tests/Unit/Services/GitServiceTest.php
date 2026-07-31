<?php

namespace Tests\Unit\Services;

use App\Services\GitService;
use RuntimeException;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

/**
 * Ported from reference/tests/Unit/gitService.test.ts (3 cases: clone
 * succeeds, clone throws on failure, pull succeeds). The reference version
 * hits github.com over the real network; this port uses a FakeProcessRunner
 * instead (see docs/porting-notes.md) — the spec calls for tests that need
 * no real git remote. Extended with cases pinning the EXACT argv GitService
 * builds, since the spec requires every git command to be verbatim,
 * including `-c safe.directory=*` and the checkout branching inside
 * pull() — verified by mutation: reordering or dropping any flag in
 * clone()/pull() makes the corresponding argv assertion below fail.
 */
class GitServiceTest extends TestCase
{
    public function test_clone_returns_output_string_on_success(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueSuccess();
        $service = new GitService($runner, '/nonexistent/key');

        $output = $service->clone('https://example.com/repo.git', '/tmp/target', 'main');

        $this->assertIsString($output);
        $this->assertSame('Cloned https://example.com/repo.git into /tmp/target', $output);
    }

    public function test_clone_throws_on_invalid_repo(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueFailure(128, '', "fatal: repository 'https://example.com/nope.git' not found");
        $service = new GitService($runner, '/nonexistent/key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("fatal: repository 'https://example.com/nope.git' not found");
        $service->clone('https://example.com/nope.git', '/tmp/nope', 'main');
    }

    public function test_clone_builds_the_exact_verbatim_command(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueSuccess();
        $service = new GitService($runner, '/nonexistent/key');

        $service->clone('https://example.com/repo.git', '/tmp/target', 'main');

        $this->assertSame(
            ['git', 'clone', '--branch', 'main', '-c', 'safe.directory=*', 'https://example.com/repo.git', '/tmp/target'],
            $runner->calls[0]['command']
        );
    }

    public function test_pull_returns_output_string_for_existing_repo(): void
    {
        $runner = new FakeProcessRunner;
        $runner
            ->queueSuccess() // git config safe.directory *
            ->queueSuccess() // git fetch origin main
            ->queueSuccess('main') // git rev-parse --abbrev-ref HEAD -> already on branch
            ->queueSuccess('Already up to date.'); // git pull origin main

        $service = new GitService($runner, '/nonexistent/key');

        $output = $service->pull('/tmp/existing', 'main');

        $this->assertIsString($output);
        $this->assertSame('Already up to date.', $output);
        $this->assertCount(4, $runner->calls);
    }

    /**
     * QC B4/B5/B6: only the checkout/checkout-b argv (index 4) and clone's
     * argv were ever asserted. `git config safe.directory *`'s argument,
     * every one of fetch/rev-parse/pull's arguments, and the cwd on ALL
     * five pull() sub-commands were unpinned — mutating any of them
     * (e.g. `safe.directory *` -> `safe.directory .`, `rev-parse
     * --abbrev-ref HEAD` -> `rev-parse HEAD`, or any cwd -> null) passed the
     * full suite. Asserting the full command sequence AND cwd together, for
     * both the "already on target branch" and "must switch branch" paths,
     * closes all three at once — each is `reference/src/services/gitService.ts`'s
     * `baseDir: repoPath`-scoped `git.raw()` call, verbatim.
     */
    public function test_pull_issues_the_exact_verbatim_command_sequence_when_already_on_target_branch(): void
    {
        $runner = new FakeProcessRunner;
        $runner
            ->queueSuccess()
            ->queueSuccess()
            ->queueSuccess('main')
            ->queueSuccess('Already up to date.');

        $service = new GitService($runner, '/nonexistent/key');
        $service->pull('/tmp/existing', 'main');

        $this->assertSame([
            ['git', 'config', 'safe.directory', '*'],
            ['git', 'fetch', 'origin', 'main'],
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'],
            ['git', 'pull', 'origin', 'main'],
        ], $runner->commands());

        foreach ($runner->calls as $call) {
            $this->assertSame('/tmp/existing', $call['cwd']);
        }
    }

    public function test_pull_issues_the_exact_verbatim_command_sequence_when_switching_to_an_existing_local_branch(): void
    {
        $runner = new FakeProcessRunner;
        $runner
            ->queueSuccess() // config
            ->queueSuccess() // fetch
            ->queueSuccess('develop') // rev-parse -> currently on develop
            ->queueSuccess('  main') // branch --list main -> found locally
            ->queueSuccess() // checkout main
            ->queueSuccess('Already up to date.'); // pull

        $service = new GitService($runner, '/nonexistent/key');
        $service->pull('/tmp/existing', 'main');

        $this->assertSame([
            ['git', 'config', 'safe.directory', '*'],
            ['git', 'fetch', 'origin', 'main'],
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'],
            ['git', 'branch', '--list', 'main'],
            ['git', 'checkout', 'main'],
            ['git', 'pull', 'origin', 'main'],
        ], $runner->commands());

        foreach ($runner->calls as $call) {
            $this->assertSame('/tmp/existing', $call['cwd']);
        }
    }

    public function test_pull_issues_the_exact_verbatim_command_sequence_when_creating_a_new_tracked_branch(): void
    {
        $runner = new FakeProcessRunner;
        $runner
            ->queueSuccess() // config
            ->queueSuccess() // fetch
            ->queueSuccess('develop') // rev-parse -> currently on develop
            ->queueSuccess('') // branch --list main -> not found locally
            ->queueSuccess() // checkout -b
            ->queueSuccess('Already up to date.'); // pull

        $service = new GitService($runner, '/nonexistent/key');
        $service->pull('/tmp/existing', 'main');

        $this->assertSame([
            ['git', 'config', 'safe.directory', '*'],
            ['git', 'fetch', 'origin', 'main'],
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'],
            ['git', 'branch', '--list', 'main'],
            ['git', 'checkout', '-b', 'main', '--track', 'origin/main'],
            ['git', 'pull', 'origin', 'main'],
        ], $runner->commands());

        foreach ($runner->calls as $call) {
            $this->assertSame('/tmp/existing', $call['cwd']);
        }
    }

    public function test_pull_ignores_safe_directory_config_failure(): void
    {
        $runner = new FakeProcessRunner;
        $runner
            ->queueFailure(1) // git config safe.directory * fails, must be ignored
            ->queueSuccess() // fetch
            ->queueSuccess('main') // rev-parse
            ->queueSuccess('Already up to date.'); // pull

        $service = new GitService($runner, '/nonexistent/key');
        $output = $service->pull('/tmp/existing', 'main');

        $this->assertSame('Already up to date.', $output);
    }

    public function test_ls_remote_returns_branch_names_stripped_of_refs_heads_prefix(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueSuccess("abc123\trefs/heads/main\ndef456\trefs/heads/develop\nghi789\trefs/tags/v1.0\n");
        $service = new GitService($runner, '/nonexistent/key');

        $branches = $service->lsRemote('https://example.com/repo.git');

        $this->assertSame(['main', 'develop'], $branches);
        $this->assertSame(
            ['git', 'ls-remote', '--heads', 'https://example.com/repo.git'],
            $runner->calls[0]['command']
        );
    }

    public function test_sets_git_ssh_command_only_when_key_file_exists(): void
    {
        $keyPath = tempnam(sys_get_temp_dir(), 'bridge-ssh-key-');
        try {
            $runner = new FakeProcessRunner;
            $runner->queueSuccess();
            $service = new GitService($runner, $keyPath);

            $service->clone('https://example.com/repo.git', '/tmp/target', 'main');

            $env = $runner->calls[0]['env'];
            $this->assertArrayHasKey('GIT_SSH_COMMAND', $env);
            $this->assertSame(
                "ssh -i {$keyPath} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null",
                $env['GIT_SSH_COMMAND']
            );
        } finally {
            unlink($keyPath);
        }
    }

    public function test_does_not_set_git_ssh_command_when_key_file_is_missing(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueSuccess();
        $service = new GitService($runner, '/definitely/does/not/exist/id_rsa');

        $service->clone('https://example.com/repo.git', '/tmp/target', 'main');

        $this->assertArrayNotHasKey('GIT_SSH_COMMAND', $runner->calls[0]['env']);
    }

    /**
     * checkout()/revParseHead()/lastCommitSubject() were added in Phase 3 so
     * every git argv deployApp.ts issues (checkout <sha>, rev-parse HEAD,
     * log -1 --format=%s) lives in GitService alongside the rest, with the
     * same FakeProcessRunner coverage as clone()/pull()/lsRemote().
     */
    public function test_checkout_issues_the_exact_verbatim_command(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueSuccess();
        $service = new GitService($runner, '/nonexistent/key');

        $service->checkout('/tmp/existing', 'aabbccddee112233445566778899aabbccddee11');

        $this->assertSame(
            ['git', 'checkout', 'aabbccddee112233445566778899aabbccddee11'],
            $runner->calls[0]['command']
        );
        $this->assertSame('/tmp/existing', $runner->calls[0]['cwd']);
    }

    public function test_checkout_throws_on_failure(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueFailure(1, '', "error: pathspec 'deadbeef' did not match any file(s) known to git");
        $service = new GitService($runner, '/nonexistent/key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("error: pathspec 'deadbeef' did not match any file(s) known to git");
        $service->checkout('/tmp/existing', 'deadbeef');
    }

    public function test_rev_parse_head_returns_trimmed_sha(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueSuccess("aabbccddee112233445566778899aabbccddee11\n");
        $service = new GitService($runner, '/nonexistent/key');

        $sha = $service->revParseHead('/tmp/existing');

        $this->assertSame('aabbccddee112233445566778899aabbccddee11', $sha);
        $this->assertSame(['git', 'rev-parse', 'HEAD'], $runner->calls[0]['command']);
        $this->assertSame('/tmp/existing', $runner->calls[0]['cwd']);
    }

    public function test_rev_parse_head_throws_on_failure(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueFailure(128, '', 'fatal: not a git repository');
        $service = new GitService($runner, '/nonexistent/key');

        $this->expectException(RuntimeException::class);
        $service->revParseHead('/tmp/not-a-repo');
    }

    public function test_last_commit_subject_returns_trimmed_message(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueSuccess("Fix the thing\n");
        $service = new GitService($runner, '/nonexistent/key');

        $message = $service->lastCommitSubject('/tmp/existing');

        $this->assertSame('Fix the thing', $message);
        $this->assertSame(['git', 'log', '-1', '--format=%s'], $runner->calls[0]['command']);
        $this->assertSame('/tmp/existing', $runner->calls[0]['cwd']);
    }

    public function test_last_commit_subject_throws_on_failure(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueFailure(128, '', 'fatal: your current branch does not have any commits yet');
        $service = new GitService($runner, '/nonexistent/key');

        $this->expectException(RuntimeException::class);
        $service->lastCommitSubject('/tmp/existing');
    }
}
