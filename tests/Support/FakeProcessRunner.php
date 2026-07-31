<?php

namespace Tests\Support;

use App\Services\Process\ProcessResult;
use App\Services\Process\ProcessRunner;
use LogicException;
use Throwable;

/**
 * Test double for App\Services\Process\ProcessRunner. Queue up canned
 * outcomes in call order; every invocation is recorded in $calls so a test
 * can assert the exact argv a service built (e.g. that GitService really
 * passed `-c safe.directory=*`) without a real git binary or network.
 *
 * Three queueable outcome shapes, for three different test needs:
 *
 * - `queueSuccess()`/`queueFailure()`/`queueTimeout()`/`queue(ProcessResult)`
 *   — a canned, static ProcessResult. Covers most cases.
 * - `queueThrowable()` — the next run() call throws instead of returning.
 *   Distinct from queueTimeout(): a stall is a well-formed ProcessResult
 *   with `timedOut = true` (see ProcessResult's docblock), this is for
 *   simulating the runner itself misbehaving (e.g. to test a caller's own
 *   defensive handling around run()).
 * - `queueCallable()` — a callable invoked with run()'s exact arguments,
 *   for behaviour a canned result can't express: emitting several chunks
 *   through $onOutput over the course of one call before returning (Phase
 *   3's "log chunks append on every chunk" need) or building a ProcessResult
 *   dynamically from the arguments received.
 *
 * Phase 3's process-heavy deploy pipeline tests should reuse this rather
 * than inventing another fake.
 */
final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<ProcessResult|Throwable|callable> */
    private array $queue = [];

    /**
     * @var list<array{
     *     command: list<string>,
     *     cwd: ?string,
     *     env: array<string, string>,
     *     timeout: ?float,
     *     idleTimeout: ?float,
     * }>
     */
    public array $calls = [];

    public function queue(ProcessResult $result): static
    {
        $this->queue[] = $result;

        return $this;
    }

    public function queueSuccess(string $output = '', string $errorOutput = ''): static
    {
        return $this->queue(new ProcessResult(0, $output, $errorOutput));
    }

    public function queueFailure(int $exitCode = 1, string $output = '', string $errorOutput = ''): static
    {
        return $this->queue(new ProcessResult($exitCode, $output, $errorOutput));
    }

    /**
     * Queue a stalled/idle-timed-out outcome, mirroring exactly what
     * SymfonyProcessRunner returns when Symfony throws
     * ProcessTimedOutException internally: exit code 1, timedOut = true,
     * whatever output/error had already been captured before the kill.
     */
    public function queueTimeout(string $output = '', string $errorOutput = ''): static
    {
        return $this->queue(new ProcessResult(1, $output, $errorOutput, timedOut: true));
    }

    /**
     * Queue a throwable to be thrown the next time run() is called.
     */
    public function queueThrowable(Throwable $throwable): static
    {
        $this->queue[] = $throwable;

        return $this;
    }

    /**
     * Queue a callable, invoked with run()'s exact arguments
     * (command, cwd, env, timeout, idleTimeout, onOutput), that must return
     * a ProcessResult.
     *
     * @param  callable(list<string> $command, ?string $cwd, array<string, string> $env, ?float $timeout, ?float $idleTimeout, ?callable $onOutput): ProcessResult  $callable
     */
    public function queueCallable(callable $callable): static
    {
        $this->queue[] = $callable;

        return $this;
    }

    public function run(
        array $command,
        ?string $cwd = null,
        array $env = [],
        ?float $timeout = null,
        ?float $idleTimeout = null,
        ?callable $onOutput = null,
    ): ProcessResult {
        $this->calls[] = [
            'command' => $command,
            'cwd' => $cwd,
            'env' => $env,
            'timeout' => $timeout,
            'idleTimeout' => $idleTimeout,
        ];

        if ($this->queue === []) {
            // Deliberately strict. This used to fall back to a silent
            // ProcessResult(0, '', ''), which let a test queue fewer
            // responses than the code under test makes calls and still pass
            // — the surplus calls silently "succeeded" with empty output.
            // QC round 2 found exactly that: a test that queued 9 responses
            // for 11 calls, drifted onto a git branch it never meant to
            // exercise, and ended with an empty commit_sha while asserting
            // nothing about it. Failing loudly here makes queue misalignment
            // a test error at the call that overran, not a silent wrong-path
            // pass several calls later.
            throw new LogicException(
                'FakeProcessRunner: queue exhausted, but run() was called with: '
                .implode(' ', $command)
                .'. Queue one response per expected process invocation.'
            );
        }

        $next = array_shift($this->queue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        if ($next instanceof ProcessResult) {
            if ($onOutput !== null) {
                if ($next->output !== '') {
                    $onOutput('out', $next->output);
                }
                if ($next->errorOutput !== '') {
                    $onOutput('err', $next->errorOutput);
                }
            }

            return $next;
        }

        if (is_callable($next)) {
            return $next($command, $cwd, $env, $timeout, $idleTimeout, $onOutput);
        }

        throw new LogicException('FakeProcessRunner: queued value must be a ProcessResult, Throwable, or callable.');
    }

    /**
     * @return list<list<string>>
     */
    public function commands(): array
    {
        return array_column($this->calls, 'command');
    }
}
