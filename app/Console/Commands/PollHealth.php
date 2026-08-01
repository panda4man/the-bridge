<?php

namespace App\Console\Commands;

use App\Jobs\PollHealthChecks;
use Illuminate\Console\Command;

/**
 * Dispatches the FIRST tick of the self-rescheduling App\Jobs\PollHealthChecks
 * chain (see that class's docblock for the full design). Modelled on
 * App\Console\Commands\ResetStuckDeployments: Phase 7's container entrypoint
 * is expected to run this once at boot, alongside
 * `bridge:reset-stuck-deployments`.
 *
 * Duplicate-kickoff decision: NOT internally guarded. Two invocations start
 * two independent forever-ticking chains — duplicate HealthCheck rows and
 * roughly double outbound health-check request volume, but nothing worse:
 * HealthCheck rows are purely additive (App::query() doesn't dedupe or key
 * off "one active chain"), and no deploy-path state is touched. A stateful
 * guard was deliberately NOT built: the only guard that would actually work
 * across this job's INDEFINITE lifetime is a leased lock renewed by every
 * tick, and a stale lock left behind by a chain that died anyway (a crash,
 * a queue flush, a botched deploy of this app itself) would then block the
 * one legitimate way to recover — restarting the chain by hand. A guard
 * that can strand recovery is worse than the failure it prevents.
 *
 * What Phase 7 MUST guarantee instead: invoke `artisan bridge:poll-health`
 * exactly once per container start — not once per queue:work worker
 * process, and not on every supervisord respawn of an already-running
 * process. This is the same guarantee its entrypoint already owes the
 * migration step and `bridge:reset-stuck-deployments`; nothing new is being
 * asked of it here.
 */
class PollHealth extends Command
{
    protected $signature = 'bridge:poll-health';

    protected $description = 'Kick off the self-rescheduling health-check poll chain';

    public function handle(): int
    {
        PollHealthChecks::dispatch();

        $this->info('Health poll chain started.');

        return self::SUCCESS;
    }
}
