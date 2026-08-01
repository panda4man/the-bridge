<?php

namespace Tests\Unit;

use App\Jobs\PollHealthChecks;
use App\Models\App;
use App\Models\HealthCheck;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Throwable;

/**
 * App\Jobs\PollHealthChecks — the self-rescheduling health-check tick. See
 * that class's docblock for the full design this pins.
 *
 * QUEUE_CONNECTION=sync in phpunit.xml, so a real PollHealthChecks::dispatch()
 * would run handle() fully in-process — which itself dispatches another
 * PollHealthChecks — and under `sync`, delay() is ignored and dispatch()
 * still runs immediately, so this recurses without bound and hangs the test
 * run. Every test here calls handle() directly via app()->call() (never
 * ::dispatch()) with Bus::fake() active, so the self-redispatch is recorded
 * and asserted on rather than actually executed. Do not remove Bus::fake()
 * from any test in this file, and do not call ::dispatch() on this job
 * anywhere in the suite.
 */
class PollHealthChecksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    public function test_handle_calls_health_poller_poll_due(): void
    {
        Http::fake(fn () => Http::response('', 200));
        $app = App::factory()->create(['health_url' => 'https://example.test/health']);

        app()->call([new PollHealthChecks, 'handle']);

        $this->assertSame(1, HealthCheck::query()->where('app_id', $app->id)->count());
    }

    public function test_handle_reschedules_itself_after_the_tick_interval_on_a_normal_pass(): void
    {
        Http::fake(fn () => Http::response('', 200));
        App::factory()->create(['health_url' => 'https://example.test/health']);

        $before = now();
        app()->call([new PollHealthChecks, 'handle']);
        $after = now();

        Bus::assertDispatched(PollHealthChecks::class, function (PollHealthChecks $job) use ($before, $after): bool {
            if (! $job->delay instanceof DateTimeInterface) {
                return false;
            }

            $earliest = $before->clone()->addSeconds(PollHealthChecks::TICK_INTERVAL_SECONDS)->getTimestamp();
            $latest = $after->clone()->addSeconds(PollHealthChecks::TICK_INTERVAL_SECONDS)->getTimestamp();

            return $job->delay->getTimestamp() >= $earliest && $job->delay->getTimestamp() <= $latest;
        });
    }

    /**
     * The re-dispatch must stay on the DEFAULT queue. Phase 7's supervisord
     * runs a single `queue:work` with no `--queue` flag, which consumes
     * `default` only — so naming a queue here would mean this job, and
     * therefore ALL health polling, silently never runs in production while
     * every test stayed green.
     *
     * Verified by mutation: adding ->onQueue('health') to the re-dispatch
     * survived the entire suite (QC mutation H3-poller-on-named-queue).
     * A null `queue` property is how Laravel represents "the connection's
     * default queue".
     */
    public function test_handle_reschedules_itself_onto_the_default_queue(): void
    {
        Http::fake(fn () => Http::response('', 200));
        App::factory()->create(['health_url' => 'https://example.test/health']);

        app()->call([new PollHealthChecks, 'handle']);

        Bus::assertDispatched(
            PollHealthChecks::class,
            fn (PollHealthChecks $job): bool => $job->queue === null,
        );
    }

    public function test_tick_interval_matches_the_references_fixed_60_second_cadence(): void
    {
        // reference/src/services/healthPoller.ts has no per-app interval
        // logic — every app is polled on the same fixed 60s tick. Per-app
        // interval logic instead lives in HealthPoller::isDue(); this job
        // only needs to tick at that same fixed rate.
        $this->assertSame(60, PollHealthChecks::TICK_INTERVAL_SECONDS);
    }

    /**
     * The finally-based reschedule must survive pollDue() itself throwing —
     * not just a per-app failure inside it. HealthPoller::pollDue() already
     * hardens the per-app case with its own try/catch (see that class's
     * docblock and docs/porting-notes.md's "QC pass" B1/B1-residual), but
     * its own `App::query()->...->get()` call sits OUTSIDE that try/catch.
     * Dropping the apps table (rolled back automatically by
     * RefreshDatabase's wrapping transaction — SQLite DDL is transactional)
     * makes that query throw for real, without mutating any production
     * code, and is a stronger proof of the job's own resilience than
     * reproducing HealthPoller's specific InvalidArgumentException bug
     * scenario, which requires actually breaking its query filter to
     * observe.
     */
    public function test_handle_still_reschedules_when_poll_due_itself_throws(): void
    {
        Schema::drop('apps');

        $threw = false;

        try {
            app()->call([new PollHealthChecks, 'handle']);
        } catch (Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected pollDue() to throw when its own query has no table to read.');
        Bus::assertDispatched(PollHealthChecks::class);
    }
}
