<?php

namespace App\Services\Process;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Real ProcessRunner implementation — a thin wrapper over
 * Symfony\Component\Process\Process. Bound as the default ProcessRunner in
 * AppServiceProvider; individual services accept a ProcessRunner in their
 * constructor and default to app(ProcessRunner::class) when none is given,
 * exactly like GitService's constructor(sshKeyPath = null) pattern in the
 * reference.
 *
 * Verified against vendor/symfony/process/Process.php: passing $env to the
 * constructor merges ON TOP of the inherited environment at start() time —
 * it does not replace it (see ProcessRunner's docblock).
 */
final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(
        array $command,
        ?string $cwd = null,
        array $env = [],
        ?float $timeout = null,
        ?float $idleTimeout = null,
        ?callable $onOutput = null,
    ): ProcessResult {
        $process = $this->buildProcess($command, $cwd, $env, $timeout, $idleTimeout);

        try {
            $process->run($onOutput);
        } catch (ProcessTimedOutException) {
            // Mirrors the reference's stall handling (see ProcessResult's
            // docblock): a killed-for-stalling process is a normal result,
            // not an exception escaping the runner. Exit code 1 matches
            // deployApp.ts's own contract for this path exactly — it is not
            // Symfony's exit code (a forcibly killed process often has none),
            // it is what the reference resolves with.
            return new ProcessResult(
                1,
                $process->getOutput(),
                $process->getErrorOutput(),
                timedOut: true,
            );
        }

        return new ProcessResult(
            $process->getExitCode() ?? -1,
            $process->getOutput(),
            $process->getErrorOutput(),
        );
    }

    /**
     * Split out from run() so the exact Process configuration (timeout and
     * idleTimeout applied, unconditionally — see ProcessRunner's docblock on
     * why null must still be passed through rather than the call being
     * skipped) is independently inspectable in tests without having to run
     * a real process to observe it.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function buildProcess(
        array $command,
        ?string $cwd,
        array $env,
        ?float $timeout,
        ?float $idleTimeout,
    ): SymfonyProcess {
        $process = new SymfonyProcess($command, $cwd, $env);
        $process->setTimeout($timeout);
        $process->setIdleTimeout($idleTimeout);

        return $process;
    }
}
