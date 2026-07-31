<?php

namespace Tests\Unit\Services;

use App\Models\App;
use App\Models\HealthCheck;
use App\Services\HealthPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ported from reference/tests/Unit/healthPoller.test.ts (3 cases: records
 * "up" for a 200 response, records "down" when the request throws, skips
 * apps without health_url).
 *
 * Extended with cases for per-app health_check_interval honoring — a
 * deliberate behaviour change from the reference documented in
 * app/Services/HealthPoller.php and docs/porting-notes.md: the reference
 * writes apps.health_check_interval but never reads it, this port's
 * pollDue() does. Both an "interval not yet elapsed -> skipped" and an
 * "interval elapsed -> polled" case are included together deliberately —
 * a single case in either direction alone can't distinguish "correctly
 * scoped by interval" from "always skips" or "always polls".
 */
class HealthPollerTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_up_for_200_response(): void
    {
        Http::fake(['http://localhost:9999' => Http::response('', 200)]);
        $app = App::factory()->create(['health_url' => 'http://localhost:9999']);

        (new HealthPoller)->check($app);

        $latest = HealthCheck::findLatest($app->id);
        $this->assertNotNull($latest);
        $this->assertSame('up', $latest->status->value);
        $this->assertSame(200, $latest->http_status_code);
        $this->assertNotNull($latest->response_time_ms);
    }

    public function test_records_down_when_request_throws(): void
    {
        Http::fake(['http://localhost:9998' => fn () => throw new ConnectionException('ECONNREFUSED')]);
        $app = App::factory()->create(['health_url' => 'http://localhost:9998']);

        (new HealthPoller)->check($app);

        $latest = HealthCheck::findLatest($app->id);
        $this->assertNotNull($latest);
        $this->assertSame('down', $latest->status->value);
        $this->assertNull($latest->http_status_code);
        $this->assertNull($latest->response_time_ms);
    }

    public function test_records_down_for_non_2xx_response_with_status_code(): void
    {
        Http::fake(['http://localhost:9997' => Http::response('', 500)]);
        $app = App::factory()->create(['health_url' => 'http://localhost:9997']);

        (new HealthPoller)->check($app);

        $latest = HealthCheck::findLatest($app->id);
        $this->assertSame('down', $latest->status->value);
        $this->assertSame(500, $latest->http_status_code);
    }

    /**
     * Ported from reference/tests/Unit/healthPoller.test.ts:26 — whose
     * entire assertion is also just "fetch was not called". QC noted that,
     * on its own, this can't distinguish "correctly filtered out" from
     * "admitted, threw, and got silently swallowed by pollDue()'s
     * catch-all" — both look identical from here (no request sent either
     * way). Narrowing HealthPoller's catch (see its own comment) to
     * re-throw InvalidArgumentException specifically is what closes that
     * gap: if the query filter ever regresses and admits a null-health_url
     * app, check() throws, and it now propagates out of pollDue() instead
     * of being absorbed — this test would error, not silently stay green.
     */
    public function test_poll_due_skips_apps_without_health_url(): void
    {
        Http::fake();
        App::factory()->create(['health_url' => null]);

        (new HealthPoller)->pollDue();

        Http::assertNothingSent();
    }

    public function test_poll_due_polls_apps_with_a_health_url(): void
    {
        Http::fake(['http://localhost:9996' => Http::response('', 200)]);
        App::factory()->create(['health_url' => 'http://localhost:9996']);

        (new HealthPoller)->pollDue();

        Http::assertSentCount(1);
    }

    public function test_poll_due_skips_an_app_whose_interval_has_not_elapsed(): void
    {
        Http::fake();
        $app = App::factory()->create(['health_url' => 'http://localhost:9995', 'health_check_interval' => 300]);
        HealthCheck::query()->create([
            'app_id' => $app->id,
            'status' => 'up',
            'http_status_code' => 200,
            'response_time_ms' => 10,
        ]);

        (new HealthPoller)->pollDue();

        Http::assertNothingSent();
    }

    public function test_poll_due_polls_an_app_whose_interval_has_elapsed(): void
    {
        Http::fake(['http://localhost:9994' => Http::response('', 200)]);
        $app = App::factory()->create(['health_url' => 'http://localhost:9994', 'health_check_interval' => 1]);
        $check = HealthCheck::query()->create([
            'app_id' => $app->id,
            'status' => 'up',
            'http_status_code' => 200,
            'response_time_ms' => 10,
        ]);
        // Backdate the only check so its interval has clearly elapsed.
        DB::table('health_checks')->where('id', $check->id)->update(['checked_at' => now()->subMinutes(10)]);

        (new HealthPoller)->pollDue();

        Http::assertSentCount(1);
    }

    public function test_poll_due_polls_an_app_that_has_never_been_checked_regardless_of_interval(): void
    {
        Http::fake(['http://localhost:9993' => Http::response('', 200)]);
        App::factory()->create(['health_url' => 'http://localhost:9993', 'health_check_interval' => 3600]);

        (new HealthPoller)->pollDue();

        Http::assertSentCount(1);
    }

    /**
     * QC B1: whereNotNull('health_url') alone admits ''. check() then threw
     * InvalidArgumentException for the empty-URL app, uncaught, which killed
     * the whole pollDue() pass — a second, perfectly healthy app never got
     * polled. The reference guards with a falsy check
     * (`if (!app.health_url) continue;`), which treats '' the same as null.
     * Two apps, in this order, is deliberate — a single-app case can't
     * distinguish "the empty-URL app was skipped" from "the pass never ran
     * at all".
     */
    public function test_poll_due_skips_an_empty_string_health_url_without_aborting_the_pass(): void
    {
        Http::fake(['http://localhost:9989' => Http::response('', 200)]);
        App::factory()->create(['name' => 'A-empty', 'health_url' => '']);
        App::factory()->create(['name' => 'B-good', 'health_url' => 'http://localhost:9989']);

        (new HealthPoller)->pollDue();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'http://localhost:9989');
    }

    /**
     * QC B2: the paired skip/elapsed tests above both use intervals and
     * backdating that happen to agree with the 60s default regardless of
     * whether $app->health_check_interval is actually read (300s with a
     * fresh check: elapsed ~0 < 60 either way; 1s backdated 10 minutes:
     * elapsed 600 >= 60 either way). This case sets the per-app interval to
     * something the DEFAULT would treat oppositely: 3600s with a check only
     * 120s old — the default (60) would say "due", honoring the column must
     * say "not due". Verified by mutation: hardcoding
     * `$interval = self::DEFAULT_INTERVAL_SECONDS` in isDue() (ignoring
     * $app->health_check_interval entirely) makes this fail while every
     * other HealthPollerTest case still passes.
     */
    public function test_poll_due_honors_a_per_app_interval_that_diverges_from_the_default(): void
    {
        Http::fake();
        $app = App::factory()->create(['health_url' => 'http://localhost:9988', 'health_check_interval' => 3600]);
        $check = HealthCheck::query()->create([
            'app_id' => $app->id,
            'status' => 'up',
            'http_status_code' => 200,
            'response_time_ms' => 10,
        ]);
        DB::table('health_checks')->where('id', $check->id)->update(['checked_at' => now()->subSeconds(120)]);

        (new HealthPoller)->pollDue();

        Http::assertNothingSent();
    }

    /**
     * QC B3: the 10s request timeout (matching the reference's
     * `AbortSignal.timeout(10000)`) had zero coverage — neither shrinking it
     * to 1s nor deleting the `Http::timeout(...)` call entirely failed any
     * existing test. Http::fake()'s recorded Request object does not carry
     * the raw Guzzle request options (timeout isn't part of the PSR
     * request), so a stub callback capturing the second ($options) argument
     * is used instead of Http::assertSent()'s usual single-argument form.
     */
    public function test_uses_a_ten_second_request_timeout(): void
    {
        $capturedTimeout = null;
        Http::fake(function ($request, $options) use (&$capturedTimeout) {
            $capturedTimeout = $options['timeout'] ?? null;

            return Http::response('', 200);
        });
        $app = App::factory()->create(['health_url' => 'http://localhost:9987']);

        (new HealthPoller)->check($app);

        $this->assertSame(10, $capturedTimeout);
    }

    /**
     * QC B1-residual: the fault barrier had no test of its own actual job —
     * containing a per-app failure UNRELATED to health_url. Without this,
     * the try/catch's containment claim in HealthPoller's docblock was
     * asserted, not proven: nothing distinguished "the catch works" from
     * "the catch is dead code that happens not to matter because nothing
     * ever throws".
     *
     * Forces a real, health_url-unrelated failure: the app row is deleted
     * out from under HealthPoller (AFTER pollDue() has already loaded it
     * into memory via ->get(), from inside the faked HTTP response for its
     * own URL) so HealthCheck::record()'s INSERT hits the foreign-key
     * constraint on health_checks.app_id (SQLite foreign keys are on — see
     * the Phase 1 section above) — both check()'s own success-path
     * record() call and its catch-and-retry-as-down record() call throw,
     * and the second one escapes check() entirely (it's not itself wrapped
     * in a try). That's exactly the "a DB hiccup writing the HealthCheck
     * row" scenario the catch's own comment names.
     *
     * A-fails is created (and therefore iterated) before B-good — SQLite
     * returns unordered-query rows in rowid/insertion order for a simple
     * table scan, which is what makes B being polled AFTER A's failure
     * meaningful here rather than coincidental.
     */
    public function test_poll_due_contains_an_unrelated_per_app_failure_and_still_polls_the_rest(): void
    {
        $failing = App::factory()->create(['name' => 'A-fails', 'health_url' => 'http://localhost:9979']);
        $good = App::factory()->create(['name' => 'B-good', 'health_url' => 'http://localhost:9978']);

        Http::fake([
            'http://localhost:9979' => function () use ($failing) {
                App::query()->where('id', $failing->id)->delete();

                return Http::response('', 200);
            },
            'http://localhost:9978' => Http::response('', 200),
        ]);

        (new HealthPoller)->pollDue();

        Http::assertSentCount(2);
        $latest = HealthCheck::findLatest($good->id);
        $this->assertNotNull($latest, 'the app polled after the failing one must still have been checked');
        $this->assertSame('up', $latest->status->value);
    }
}
