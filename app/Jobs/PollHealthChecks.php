<?php

namespace App\Jobs;

use App\Services\HealthPoller;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Self-rescheduling health-check tick. This is the invocation mechanism
 * App\Services\HealthPoller's class docblock specifies (see it for the full
 * reasoning, which is settled — not open for re-litigation here): a queued
 * job, not Laravel's scheduler (needs cron or `schedule:work`) and not a
 * bespoke long-running loop.
 *
 * `handle()` calls HealthPoller::pollDue() once, then re-dispatches itself
 * with a delay — forever, once started. The reference's own cadence is a
 * fixed 60s tick (reference/src/services/healthPoller.ts has no interval
 * logic at all); per-app interval logic already lives in
 * HealthPoller::isDue(), so this job's only job is to tick at a fixed rate
 * and let isDue() decide which apps are actually due each time.
 *
 * Runs on its OWN queue, named by self::QUEUE. This reverses the Phase 3
 * position that it should share DeployApp's `default` queue, and the reversal
 * is the deliberate Phase 7 decision the previous note deferred to.
 *
 * The reason: one worker runs one job at a time, so on a shared queue a
 * `docker compose up -d --build` that legitimately takes 40 minutes blocks
 * this tick for 40 minutes — no health checks, and no Slack down-alert for
 * any OTHER app, for the whole build. The reference has no such stall; its
 * poller lives in the web process and is unaffected by deploys. That is a
 * parity regression, not merely a latency one.
 *
 * The fix is a second single-slot worker, NOT a second worker on `default`.
 * Deploy concurrency must stay at exactly 1: config/queue.php's
 * `retry_after` (90s) makes a reserved job visible again mid-run, which is
 * harmless only while the single worker holding it is the only one that
 * could pick it up. A second `default` worker turns every deploy longer
 * than 90 seconds into a duplicate deploy. Splitting by queue name keeps
 * `default` at one worker and that invariant intact.
 *
 * The failure mode the Phase 3 note warned about is real: if supervisord's
 * health worker is started with a `--queue` that does not match self::QUEUE,
 * this job is enqueued forever and never run, and ALL health polling stops
 * silently. tests/Feature/Packaging/PackagingConfigTest.php reads
 * docker/supervisord.conf and asserts the two agree, so the drift cannot
 * land green.
 *
 * Two chain-lifetime facts, both found by Phase 8's QC of the packaging:
 *
 * 1. The delayed successor is a row in the database queue, which lives on the
 *    /data volume and OUTLIVES the container. The entrypoint therefore runs
 *    `queue:clear --queue=health` immediately before its kickoff; without it
 *    each restart ADDS a chain (reproduced: one stop/start, two pending health
 *    jobs) and nothing anywhere reports the doubling.
 * 2. If the health worker is SIGKILLed mid-tick, the `finally` below never
 *    runs, and the reserved job becomes visible again after `retry_after`
 *    only to be failed immediately by `--tries=1` — so that chain is gone
 *    until the next container start re-kicks it. This is accepted rather than
 *    fixed: a `failed()` hook that re-dispatches would fork the chain in two
 *    on every ordinary throw, which is the exact hazard `--tries=1` exists to
 *    prevent. A missing health chain shows as health checks stopping; a forked
 *    one silently doubles forever.
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

    /**
     * Queue name. Read by docker/supervisord.conf's `health-worker` program
     * and pinned against it by PackagingConfigTest — do not rename one without
     * the other. See the class docblock.
     */
    public const QUEUE = 'health';

    public function __construct()
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(HealthPoller $poller): void
    {
        try {
            $poller->pollDue();
        } finally {
            self::dispatch()->delay(now()->addSeconds(self::TICK_INTERVAL_SECONDS));
        }
    }
}
