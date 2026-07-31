<?php

namespace App\Services\Process;

/**
 * Immutable outcome of a single process invocation. Deliberately mirrors
 * only what the Phase 2/3 services need (exit code, stdout, stderr, whether
 * it was killed for stalling) — no Symfony\Process type leaks past this
 * boundary, so ProcessRunner implementations (real or fake) are fully
 * interchangeable.
 *
 * $timedOut expresses the reference's stall contract
 * (reference/src/jobs/deployApp.ts:31-43): a process killed for producing no
 * output within the idle window is NOT an exception there — it is a normal
 * result the retry loop inspects (exit code 1, whatever output was
 * captured before the kill). SymfonyProcessRunner catches
 * Symfony's ProcessTimedOutException internally and converts it to a
 * ProcessResult with $timedOut = true precisely so callers (Phase 3's
 * deploy job) never need to catch a Symfony exception type themselves.
 */
final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $errorOutput,
        public readonly bool $timedOut = false,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }
}
