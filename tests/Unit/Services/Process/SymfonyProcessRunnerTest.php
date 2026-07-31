<?php

namespace Tests\Unit\Services\Process;

use App\Services\Process\ProcessResult;
use App\Services\Process\SymfonyProcessRunner;
use ReflectionMethod;
use Tests\TestCase;

/**
 * QC B9: SymfonyProcessRunner — the ONLY real ProcessRunner implementation,
 * the one Phase 3's 40-minute builds actually run through — had zero tests.
 * Every service test in this phase injects FakeProcessRunner, so nothing in
 * the suite would notice SymfonyProcessRunner breaking.
 *
 * These shell out to real `bash`/`sleep`/`echo` — they exist specifically to
 * exercise the real Symfony\Process integration, not a fake:
 *
 * - Probe 2: output chunks arrive incrementally through $onOutput, not
 *   buffered until the process exits.
 * - Probe 3: an idle timeout kills a genuinely silent process well before
 *   its own runtime would otherwise end — and, per the contract change made
 *   alongside this test file (ProcessResult::$timedOut, see its docblock),
 *   surfaces as a well-formed ProcessResult, not a thrown exception.
 * - Probe 4: an idle timeout does NOT fire when the process keeps
 *   producing output — each chunk resets the idle clock.
 * - Probe 8: $timeout = null / $idleTimeout = null are not silently
 *   defaulted back to Symfony's own 60s constructor default. This is the
 *   single most dangerous line in the class (app/Services/Process/SymfonyProcessRunner.php's
 *   buildProcess(), `$process->setTimeout($timeout)` with $timeout = null)
 *   — it looks like a no-op and is exactly the kind of line a later
 *   "cleanup" removes. Reflection is used to make this fast and
 *   deterministic: it inspects the Process object buildProcess() configures
 *   directly, rather than needing a process to actually run past the 60s
 *   mark to prove it.
 *
 *   Scope, precisely: this probe guards buildProcess()'s own construction
 *   logic, not the full run() call path — replacing run()'s
 *   `$this->buildProcess(...)` with an inline `new SymfonyProcess(...)`
 *   that skips setTimeout()/setIdleTimeout() entirely (keeping Symfony's
 *   60s default) would NOT be caught by this probe, since it never goes
 *   near buildProcess() at all. Probes 2–4 above are what exercise run()
 *   end-to-end with real, short timeouts; between the two, wiring and
 *   configuration are each covered by something, just not by the same
 *   test.
 */
class SymfonyProcessRunnerTest extends TestCase
{
    public function test_streams_output_incrementally_not_buffered_until_exit(): void
    {
        $runner = new SymfonyProcessRunner;
        $chunks = [];

        $result = $runner->run(
            ['bash', '-c', 'printf a; sleep 0.6; printf b; sleep 0.6; printf c'],
            timeout: 10.0,
            onOutput: function (string $type, string $buffer) use (&$chunks): void {
                $chunks[] = ['time' => microtime(true), 'buffer' => $buffer];
            },
        );

        $this->assertTrue($result->successful());
        $this->assertSame('abc', $result->output);

        // Buffered-until-exit would deliver this as one chunk at the very
        // end; real streaming delivers it as several, spread over time.
        $this->assertGreaterThanOrEqual(2, count($chunks), 'expected output to arrive in more than one chunk');

        $firstTime = $chunks[0]['time'];
        $lastTime = $chunks[count($chunks) - 1]['time'];
        $this->assertGreaterThan(0.9, $lastTime - $firstTime, 'expected chunks to be spread out over time, not delivered all at once');
    }

    /**
     * QC R4: a first version of this test used `['sleep', '5']`, which
     * produces no output at all, so it structurally could not tell whether
     * a stalled process's PRE-stall output survives the kill. It matters
     * because when a real 40-minute build hangs, what it printed before
     * hanging is the entire diagnostic — reference/src/jobs/deployApp.ts:46-47
     * streams every chunk before `resolve(1)` precisely so that survives.
     * This version prints to both stdout and stderr, then goes silent for
     * longer than the idle timeout, and asserts the pre-stall output is
     * still on the returned ProcessResult. Verified by mutation: replacing
     * SymfonyProcessRunner's timeout return with
     * `new ProcessResult(1, '', '', timedOut: true)` (discarding
     * $process->getOutput()/getErrorOutput()) now fails this test; dropping
     * just getErrorOutput() also fails it.
     */
    public function test_idle_timeout_preserves_output_captured_before_the_kill(): void
    {
        $runner = new SymfonyProcessRunner;
        $start = microtime(true);

        $result = $runner->run(
            ['bash', '-c', 'echo before-stall-out; echo before-stall-err 1>&2; sleep 5'],
            idleTimeout: 1.0,
        );

        $elapsed = microtime(true) - $start;

        // Killed well before the full 5s sleep would have finished — proves
        // the idle timeout actually fired (starting from the last output,
        // not from process start) rather than the process simply completing
        // on its own.
        $this->assertLessThan(3.0, $elapsed);
        $this->assertInstanceOf(ProcessResult::class, $result);
        $this->assertTrue($result->timedOut);
        $this->assertSame(1, $result->exitCode);
        $this->assertFalse($result->successful());
        $this->assertStringContainsString('before-stall-out', $result->output);
        $this->assertStringContainsString('before-stall-err', $result->errorOutput);
    }

    public function test_idle_timeout_does_not_fire_while_output_keeps_arriving(): void
    {
        $runner = new SymfonyProcessRunner;
        $start = microtime(true);

        // 5 ticks x 0.4s apart = ~2.0s total. Each tick's output resets the
        // idle clock, so a 1.0s idle timeout (> the 0.4s gap between ticks,
        // < the 2.0s total runtime) must survive the whole run without
        // throwing — only a TOTAL-timeout of 1.0s would kill this.
        $result = $runner->run(
            ['bash', '-c', 'for i in 1 2 3 4 5; do echo tick; sleep 0.4; done'],
            idleTimeout: 1.0,
        );

        $elapsed = microtime(true) - $start;

        $this->assertTrue($result->successful());
        $this->assertSame(0, $result->exitCode);
        $this->assertFalse($result->timedOut);
        $this->assertGreaterThan(1.5, $elapsed, 'expected the process to run past a single idle window since output kept resetting it');
    }

    public function test_null_timeout_and_idle_timeout_are_not_defaulted_by_symfony(): void
    {
        $buildProcess = new ReflectionMethod(SymfonyProcessRunner::class, 'buildProcess');
        $buildProcess->setAccessible(true);
        $runner = new SymfonyProcessRunner;

        $process = $buildProcess->invoke($runner, ['true'], null, [], null, null);
        $this->assertNull(
            $process->getTimeout(),
            'passing $timeout = null must clear Symfony Process\'s own 60-second constructor default, not leave it in place'
        );
        $this->assertNull($process->getIdleTimeout());

        // And the values really do flow through when non-null, proving the
        // above isn't null just because nothing was ever set.
        $processWithLimits = $buildProcess->invoke($runner, ['true'], null, [], 5.5, 2.5);
        $this->assertSame(5.5, $processWithLimits->getTimeout());
        $this->assertSame(2.5, $processWithLimits->getIdleTimeout());
    }
}
