<?php

namespace Tests\Feature;

use App\Jobs\DeployApp;
use App\Jobs\PollHealthChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `php artisan bridge:poll-health` — the kickoff for the self-rescheduling
 * App\Jobs\PollHealthChecks chain. Modelled on
 * tests/Feature/ResetStuckDeploymentsTest.php.
 *
 * QUEUE_CONNECTION=sync in phpunit.xml, so a real dispatch here would run
 * the job fully in-process and, per PollHealthChecksTest's docblock,
 * recurse without bound. Bus::fake() is mandatory in every test in this
 * file.
 */
class PollHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The entrypoint runs `queue:clear --queue=health` immediately before the
     * kickoff, because the chain's delayed successor is a database row on the
     * /data volume and outlives the container: without it, every restart adds
     * a chain instead of starting one.
     *
     * The packaging test pins that the entrypoint calls it. This pins that
     * calling it does what the entrypoint assumes — cancels the old chain and
     * leaves a queued deploy alone.
     */
    public function test_clearing_the_health_queue_cancels_a_surviving_chain_without_touching_queued_deploys(): void
    {
        // The real database queue, not Bus::fake(): the point is the rows.
        // Dispatched rather than pushed, so each job picks its own queue the
        // way it does in the container (phpunit.xml runs `sync` by default).
        dispatch((new PollHealthChecks)->onConnection('database')->delay(60));
        dispatch((new DeployApp(1))->onConnection('database'));

        $this->assertSame(1, DB::table('jobs')->where('queue', PollHealthChecks::QUEUE)->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count());

        $this->artisan('queue:clear', [
            'connection' => 'database',
            '--queue' => PollHealthChecks::QUEUE,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, DB::table('jobs')->where('queue', PollHealthChecks::QUEUE)->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count());
    }

    public function test_it_dispatches_the_first_tick_immediately_with_no_delay(): void
    {
        Bus::fake();

        $this->artisan('bridge:poll-health')
            ->expectsOutputToContain('Health poll chain started.')
            ->assertExitCode(0);

        Bus::assertDispatched(PollHealthChecks::class, fn (PollHealthChecks $job): bool => $job->delay === null);
    }
}
