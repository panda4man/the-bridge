<?php

namespace Tests\Feature;

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Models\App;
use App\Models\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ported from reference/src/db.ts resetStuckDeployments(), exercised via
 * `php artisan bridge:reset-stuck-deployments`.
 */
class ResetStuckDeploymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fails_running_and_pending_deployments_and_appends_the_interrupt_note(): void
    {
        $app = App::create(['name' => 'Stuck App', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/stuck', 'status' => AppStatus::Deploying]);

        $running = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Running, 'log' => "building...\n"]);
        $pending = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);
        $finished = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Success, 'commit_sha' => 'abc']);

        $this->artisan('bridge:reset-stuck-deployments')
            ->expectsOutputToContain('Reset 2 stuck deployment(s).')
            ->assertExitCode(0);

        $running->refresh();
        $pending->refresh();
        $finished->refresh();
        $app->refresh();

        $this->assertSame(DeploymentStatus::Failed, $running->status);
        $this->assertSame(DeploymentStatus::Failed, $pending->status);
        $this->assertNotNull($running->finished_at);
        $this->assertNotNull($pending->finished_at);
        $this->assertStringEndsWith("building...\n\n[Bridge] Container restarted — deployment interrupted.\n", $running->log);
        $this->assertSame("\n[Bridge] Container restarted — deployment interrupted.\n", $pending->log);

        // Untouched: already-finished deployment stays success.
        $this->assertSame(DeploymentStatus::Success, $finished->status);

        // The owning app, marked `deploying`, is put back to `failed`.
        $this->assertSame(AppStatus::Failed, $app->status);
    }

    public function test_it_does_not_touch_apps_that_were_not_deploying(): void
    {
        $app = App::create(['name' => 'Idle App', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/idle', 'status' => AppStatus::Success]);

        $this->artisan('bridge:reset-stuck-deployments')->assertExitCode(0);

        $this->assertSame(AppStatus::Success, $app->fresh()->status);
    }

    public function test_it_reports_zero_when_nothing_is_stuck(): void
    {
        $this->artisan('bridge:reset-stuck-deployments')
            ->expectsOutputToContain('Reset 0 stuck deployment(s).')
            ->assertExitCode(0);
    }
}
