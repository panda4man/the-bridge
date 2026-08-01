<?php

namespace App\Jobs;

use App\Services\HealthPoller;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Self-rescheduling health-check tick. This is the invocation mechanism
 * App\Services\HealthPoller's class docblock specifies (see it for the full
 * reasoning, which is settled — not open for re-litigation here): a queued
 * job processed by the SAME `queue:work` worker Phase 3's DeployApp already
 * runs under, not Laravel's scheduler (needs cron or `schedule:work` — a
 * third process) and not a bespoke long-running loop (also a third
 * process). Phase 7's supervisord budget is a web server plus ONE
 * `queue:work`.
 *
 * `handle()` calls HealthPoller::pollDue() once, then re-dispatches itself
 * with a delay — forever, once started. The reference's own cadence is a
 * fixed 60s tick (reference/src/services/healthPoller.ts has no interval
 * logic at all); per-app interval logic already lives in
 * HealthPoller::isDue(), so this job's only job is to tick at a fixed rate
 * and let isDue() decide which apps are actually due each time.
 *
 * Deliberately stays on the DEFAULT queue, same as DeployApp (and the
 * rollback deploys it can chain) — see docs/porting-notes.md's Phase 3
 * notes. Naming a queue here would require Phase 7's supervisord
 * `queue:work` invocation to pass a matching `--queue` flag, and a mismatch
 * would mean this job — and therefore ALL health polling — silently never
 * runs. Not done here; if head-of-line blocking between deploys and health
 * polls (see below) ever becomes a real problem, that is a Phase 7 decision
 * to make deliberately, with both processes' queue names chosen together.
 *
 * Ordering consequence worth knowing (not a bug): a single `queue:work`
 * worker runs one job at a time, so a long deploy blocks this job's tick
 * for the deploy's full duration. The poller simply catches up on its next
 * tick once the worker frees up — HealthCheck rows are additive, not a
 * fixed-size buffer, so nothing is lost, only delayed.
 *
 * The re-dispatch lives in `finally`, not at the end of a happy path — this
 * is the single most important line in the class. An uncaught throw here is
 * not a degraded pass, it is a PERMANENT outage: there is no cron/scheduler
 * backstop to notice the chain silently stopped. HealthPoller::pollDue()
 * already contains a per-app failure so it can't abort mid-pass (see its own
 * docblock), but it deliberately RE-THROWS InvalidArgumentException when its
 * own query filter is broken — a bug there must not be allowed to also kill
 * the chain that would otherwise keep surfacing it on every tick.
 */
class PollHealthChecks implements ShouldQueue
{
    use Queueable;

    /**
     * Seconds between ticks. Matches the reference's fixed cadence exactly
     * (see class docblock) — this is a tick rate, not a polling interval;
     * HealthPoller::isDue() is what actually gates each app.
     */
    public const TICK_INTERVAL_SECONDS = 60;

    public function handle(HealthPoller $poller): void
    {
        try {
            $poller->pollDue();
        } finally {
            self::dispatch()->delay(now()->addSeconds(self::TICK_INTERVAL_SECONDS));
        }
    }
}
