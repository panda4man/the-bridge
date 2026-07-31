<?php

namespace App\Services;

use App\Enums\HealthStatus;
use App\Models\App;
use App\Models\HealthCheck;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Ported from reference/src/services/healthPoller.ts's runHealthChecks().
 *
 * Behaviour change from the reference, decided for this port: the reference
 * writes apps.health_check_interval but never reads it — every app is
 * polled on the same fixed 60s cadence regardless of the column. Phase 1
 * kept the column specifically so this service honors it. isDue() treats it
 * as the per-app polling interval in seconds, defaulting to 60 when unset
 * (App::$attributes already defaults the column to 60 at the DB level, so
 * "unset" only happens for a row created before that default existed).
 *
 * Invocation mechanism (see docs/porting-notes.md for the full writeup):
 * this class is a single-pass, side-effect-explicit service
 * (pollDue()/check()) with no loop and no scheduling of its own — Phase 2
 * excludes jobs/HTTP/Filament, so the actual "run me every N seconds"
 * wiring is deferred to whichever later phase adds it. The design is built
 * so that wiring costs zero extra processes under Phase 7's supervisord
 * setup (web server + one queue:work): a self-rescheduling queued job
 * processed by the existing queue:work worker, not a scheduled command
 * (which needs cron or `schedule:work` — a third process) or a bespoke
 * long-running loop (also a third process).
 *
 * Uses the `Http` facade, not a raw cURL/Guzzle call, specifically so tests
 * can drive it with `Http::fake()` — no real network needed.
 */
final class HealthPoller
{
    private const DEFAULT_INTERVAL_SECONDS = 60;

    private const REQUEST_TIMEOUT_SECONDS = 10;

    /**
     * One pass over every app with a health_url, polling those whose
     * interval has elapsed (or that have never been checked) and recording
     * the result. Apps not yet due are skipped entirely — no request is
     * made for them.
     */
    public function pollDue(): void
    {
        // whereNotNull() alone admits '' — Filament text inputs persist ''
        // by default (Phase 4), and the reference guards with a falsy check
        // (`if (!app.health_url) continue;`, reference/src/services/healthPoller.ts:8),
        // not a null check. Excluding '' here keeps check()'s own guard from
        // ever firing during a normal pass.
        App::query()
            ->whereNotNull('health_url')
            ->where('health_url', '!=', '')
            ->get()
            ->each(function (App $app): void {
                if (! $this->isDue($app)) {
                    return;
                }

                try {
                    $this->check($app);
                } catch (InvalidArgumentException $e) {
                    // check()'s own health_url guard threw, which means an
                    // app with no health_url reached here despite the query
                    // filter above — i.e. the filter itself is broken.
                    // Deliberately NOT swallowed: absorbing this alongside
                    // every other Throwable would make a broken filter
                    // indistinguishable from a correctly-empty pass (both
                    // "no request sent" either way), silently degrading
                    // test_poll_due_skips_apps_without_health_url and its
                    // empty-string counterpart into tests that can't tell
                    // "skipped" from "attempted, threw, and got absorbed".
                    // Letting it propagate makes a broken filter fail loudly
                    // instead. Verified by mutation: this is what turns
                    // removing whereNotNull()/the '' exclusion above from a
                    // silent pass into an observable test failure.
                    throw $e;
                } catch (Throwable) {
                    // Anything else — a DB hiccup writing the HealthCheck
                    // row, a DNS failure that somehow escaped check()'s own
                    // internal catch, etc. — is a genuine per-app runtime
                    // failure unrelated to the health_url guard, and must
                    // not abort the pass for every other app. check()
                    // already recycles ordinary request failures into a
                    // "down" HealthCheck row itself; this is a backstop for
                    // anything that gets past that. Pinned by
                    // test_poll_due_contains_an_unrelated_per_app_failure_and_still_polls_the_rest.
                }
            });
    }

    /**
     * Whether $app's health_check_interval has elapsed since its last
     * recorded check. An app with no prior check is always due.
     */
    public function isDue(App $app): bool
    {
        $latest = HealthCheck::findLatest($app->id);
        if (! $latest || ! $latest->checked_at) {
            return true;
        }

        $interval = $app->health_check_interval ?? self::DEFAULT_INTERVAL_SECONDS;
        $elapsed = now()->getTimestamp() - $latest->checked_at->getTimestamp();

        return $elapsed >= $interval;
    }

    /**
     * Check a single app's health_url and record the outcome, regardless of
     * whether it is "due" — callers that already know they want a check
     * (e.g. an on-demand recheck) can call this directly instead of
     * pollDue().
     *
     * Ported from the reference's fetch-with-10s-timeout / catch-and-record
     * pair: a successful HTTP response (2xx, mirroring fetch's `res.ok`)
     * records `up` with its status code and elapsed ms; any
     * thrown exception (DNS failure, connection refused, timeout) records
     * `down` with both fields null — the reference draws the same
     * distinction between "got an HTTP response" (even an error one) and
     * "the request itself failed."
     */
    public function check(App $app): HealthCheck
    {
        if (! $app->health_url) {
            throw new InvalidArgumentException('HealthPoller::check() requires an app with a health_url');
        }

        $start = microtime(true);

        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)->get($app->health_url);
            $elapsedMs = (int) round((microtime(true) - $start) * 1000);
            $status = $response->successful() ? HealthStatus::Up : HealthStatus::Down;

            return HealthCheck::record($app->id, $status->value, $response->status(), $elapsedMs);
        } catch (Throwable) {
            return HealthCheck::record($app->id, HealthStatus::Down->value, null, null);
        }
    }
}
