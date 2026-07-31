<?php

namespace App\Services\Process;

/**
 * Injectable seam around shell-command execution. Every Phase 2 service that
 * shells out (GitService, ContainerStatusService) takes one of these instead
 * of constructing Symfony\Component\Process\Process directly, so tests can
 * substitute a fake that returns canned output without a real git remote or
 * Docker daemon.
 *
 * Phase 3 (the deploy pipeline) will lean on this same interface for the
 * build/deploy shell-out, and needs an IDLE timeout (a 40-minute build that
 * keeps printing output must not be killed) rather than a wall-clock total
 * timeout. That is why $timeout and $idleTimeout are both first-class
 * parameters here, and why `run()` accepts a streaming $onOutput callback —
 * Phase 2's own callers (GitService, ContainerStatusService) only use
 * $timeout and never pass $onOutput, but the seam does not bake in an
 * assumption that would block Phase 3's streaming-with-idle-timeout need.
 *
 * A process killed for stalling (idle timeout exceeded) is a normal,
 * well-formed ProcessResult — `$result->timedOut === true`, exit code 1,
 * whatever output/error was captured before the kill — never a thrown
 * exception. This mirrors the reference's stall contract
 * (reference/src/jobs/deployApp.ts:31-43: kill, emit a message through the
 * output callback, resolve with exit code 1, let the retry loop decide).
 * SymfonyProcessRunner is what actually catches
 * Symfony\Component\Process\Exception\ProcessTimedOutException and converts
 * it — no Symfony type ever needs to be caught by a caller of this
 * interface.
 */
interface ProcessRunner
{
    /**
     * @param  list<string>  $command  Argv-style command, never a shell string — no shell interpolation.
     * @param  array<string, string>  $env  Extra/overriding env vars. Merged ON TOP of the inherited
     *                                      environment (Symfony\Process semantics: a key set here wins;
     *                                      any key not set here still comes from the parent process's
     *                                      env, so PATH etc. survive untouched). Pass [] to inherit
     *                                      the environment unmodified.
     * @param  float|null  $timeout  Wall-clock ceiling in seconds, or null for no limit.
     * @param  float|null  $idleTimeout  Kill the process only after this many seconds pass with NO
     *                                   output, or null for no idle limit. Use this (not $timeout) for
     *                                   anything that streams output over a long, unpredictable duration.
     * @param  (callable(string $type, string $buffer): void)|null  $onOutput  Invoked once per output
     *                                                                         chunk while the process runs. $type is Process::OUT or
     *                                                                         Process::ERR. Optional — omit for simple run-and-collect usage.
     */
    public function run(
        array $command,
        ?string $cwd = null,
        array $env = [],
        ?float $timeout = null,
        ?float $idleTimeout = null,
        ?callable $onOutput = null,
    ): ProcessResult;
}
