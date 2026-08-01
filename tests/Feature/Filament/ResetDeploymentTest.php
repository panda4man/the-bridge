<?php

namespace Tests\Feature\Filament;

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Filament\Resources\Deployments\Pages\ViewDeployment;
use App\Models\App;
use App\Models\Deployment;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The manual Reset control on the deployment page.
 *
 * Ports reference/src/routes/deployments.ts:56-70. The reference has no test
 * for it; the case that matters most here is the one its own implementation
 * gets wrong — a reset that did nothing still reported success.
 */
class ResetDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private App $app_;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->app_ = App::factory()->create([
            'name' => 'Stuck',
            'path' => '/repos/stuck',
            'status' => AppStatus::Deploying,
        ]);
    }

    private function makeDeployment(array $overrides = []): Deployment
    {
        return Deployment::create(array_merge([
            'app_id' => $this->app_->id,
            'status' => DeploymentStatus::Running,
            'started_at' => now()->subMinutes(30),
        ], $overrides));
    }

    // --- Visibility: the reference renders the button only while unfinished ---

    public function test_reset_is_offered_for_a_running_deployment(): void
    {
        $deployment = $this->makeDeployment();

        Livewire::test(ViewDeployment::class, ['record' => $deployment->getRouteKey()])
            ->assertActionVisible('reset');
    }

    public function test_reset_is_offered_for_a_pending_deployment(): void
    {
        $deployment = $this->makeDeployment(['status' => DeploymentStatus::Pending]);

        // A deployment stuck in the queue is exactly what this control exists
        // to unstick — show.ejs:15 lists both statuses, not just running.
        Livewire::test(ViewDeployment::class, ['record' => $deployment->getRouteKey()])
            ->assertActionVisible('reset');
    }

    public function test_reset_is_not_offered_for_a_successful_deployment(): void
    {
        $deployment = $this->makeDeployment(['status' => DeploymentStatus::Success]);

        Livewire::test(ViewDeployment::class, ['record' => $deployment->getRouteKey()])
            ->assertActionHidden('reset');
    }

    public function test_reset_is_not_offered_for_a_failed_deployment(): void
    {
        $deployment = $this->makeDeployment(['status' => DeploymentStatus::Failed]);

        // Already failed: there is nothing to fail.
        Livewire::test(ViewDeployment::class, ['record' => $deployment->getRouteKey()])
            ->assertActionHidden('reset');
    }

    // --- The reset itself ---

    public function test_resetting_fails_the_deployment_and_stamps_a_finish_time(): void
    {
        $deployment = $this->makeDeployment();

        Livewire::test(ViewDeployment::class, ['record' => $deployment->getRouteKey()])
            ->callAction('reset');

        $deployment->refresh();

        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        // Without finished_at the deployments table shows a blank duration
        // forever — durationText() needs both ends.
        $this->assertNotNull($deployment->finished_at);
    }

    public function test_resetting_puts_the_app_back_to_failed(): void
    {
        $deployment = $this->makeDeployment();

        Livewire::test(ViewDeployment::class, ['record' => $deployment->getRouteKey()])
            ->callAction('reset');

        // The app is left `deploying` otherwise, and a `deploying` app is what
        // the panel shows a spinner for — the deployment would be unstuck and
        // the app would not.
        $this->assertSame(AppStatus::Failed, $this->app_->refresh()->status);
    }

    public function test_resetting_reports_success(): void
    {
        $deployment = $this->makeDeployment();

        Livewire::test(ViewDeployment::class, ['record' => $deployment->getRouteKey()])
            ->callAction('reset');

        // The reference's flash text, verbatim.
        Notification::assertNotified(
            Notification::make()->success()->title('Deployment reset to failed.')
        );
    }

    public function test_resetting_only_touches_the_deployments_own_app(): void
    {
        $other = App::factory()->create([
            'name' => 'Other',
            'path' => '/repos/other',
            'status' => AppStatus::Deploying,
        ]);
        Deployment::create(['app_id' => $other->id, 'status' => DeploymentStatus::Running]);

        $deployment = $this->makeDeployment();

        Livewire::test(ViewDeployment::class, ['record' => $deployment->getRouteKey()])
            ->callAction('reset');

        // A second app mid-deploy must not be failed by someone resetting an
        // unrelated deployment.
        $this->assertSame(AppStatus::Deploying, $other->refresh()->status);
    }

    // --- The defect this port fixes ---

    public function test_resetting_a_deployment_that_finished_since_the_page_rendered_changes_nothing(): void
    {
        $deployment = $this->makeDeployment();

        $page = Livewire::test(ViewDeployment::class, ['record' => $deployment->getRouteKey()]);

        // The worker finishes between the render that drew the button and the
        // click that submits it — the whole point of a page that polls a live
        // deploy.
        $deployment->forceFill([
            'status' => DeploymentStatus::Success,
            'finished_at' => now(),
        ])->save();
        $this->app_->forceFill(['status' => AppStatus::Success])->save();

        $page->callAction('reset');

        // A successful deployment must not be rewritten to failed by a stale
        // button, and its app must not be dragged down with it.
        $this->assertSame(DeploymentStatus::Success, $deployment->refresh()->status);
        $this->assertSame(AppStatus::Success, $this->app_->refresh()->status);
    }

    public function test_a_reset_that_did_nothing_is_not_reported_as_success(): void
    {
        $deployment = $this->makeDeployment();

        $page = Livewire::test(ViewDeployment::class, ['record' => $deployment->getRouteKey()]);

        $deployment->forceFill(['status' => DeploymentStatus::Success])->save();

        $page->callAction('reset');

        // The listed defect: reference/src/routes/deployments.ts flashes
        // 'Deployment reset to failed.' unconditionally, OUTSIDE its own
        // `if (resetable)` block, so the operator is told a state change
        // happened that did not. Filament re-evaluates visible() against a
        // freshly-loaded record at both mount and call, so the action never
        // runs and the message is never sent — see ResetDeploymentAction's
        // docblock for why that is the whole fix and there is no second guard
        // inside the action.
        Notification::assertNotNotified(
            Notification::make()->success()->title('Deployment reset to failed.')
        );
    }
}
