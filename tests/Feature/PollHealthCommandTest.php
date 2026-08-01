<?php

namespace Tests\Feature;

use App\Jobs\PollHealthChecks;
use Illuminate\Support\Facades\Bus;
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
    public function test_it_dispatches_the_first_tick_immediately_with_no_delay(): void
    {
        Bus::fake();

        $this->artisan('bridge:poll-health')
            ->expectsOutputToContain('Health poll chain started.')
            ->assertExitCode(0);

        Bus::assertDispatched(PollHealthChecks::class, fn (PollHealthChecks $job): bool => $job->delay === null);
    }
}
