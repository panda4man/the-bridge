<?php

namespace Tests\Feature\Filament;

use App\Enums\DeploymentStatus;
use App\Filament\Resources\Deployments\DeploymentResource;
use App\Filament\Resources\Deployments\Pages\ListDeployments;
use App\Filament\Resources\Deployments\Pages\ViewDeployment;
use App\Jobs\DeployApp;
use App\Models\App;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The read-only deployments resource and the Rollback action on it. Ports
 * reference/tests/Feature/rollback.test.ts.
 */
class DeploymentResourceTest extends TestCase
{
    use RefreshDatabase;

    private App $app_;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        Bus::fake();

        $this->app_ = App::factory()->create([
            'name' => 'RB',
            'path' => '/repos/rb',
            'branch' => 'main',
        ]);
    }

    private function makeDeployment(array $overrides = []): Deployment
    {
        return Deployment::create(array_merge([
            'app_id' => $this->app_->id,
            'status' => DeploymentStatus::Success,
        ], $overrides));
    }

    // --- Read-only ---

    public function test_the_resource_has_no_create_edit_or_delete_route(): void
    {
        $pages = array_keys(DeploymentResource::getPages());

        // Deployments are an append-only record of what the worker did.
        $this->assertSame(['index', 'view'], $pages);
    }

    public function test_the_resource_refuses_create_edit_and_delete(): void
    {
        $deployment = $this->makeDeployment();

        // Belt and braces with the missing routes above: these are what stop
        // Filament rendering buttons (including in the relation manager on
        // the app view page) that would link to pages that do not exist.
        $this->assertFalse(DeploymentResource::canCreate());
        $this->assertFalse(DeploymentResource::canEdit($deployment));
        $this->assertFalse(DeploymentResource::canDelete($deployment));
    }

    public function test_the_list_shows_a_short_sha_and_the_computed_duration(): void
    {
        $deployment = $this->makeDeployment([
            'commit_sha' => 'abc123def456abc123def456abc123def456abc1',
            'commit_message' => 'Fix the thing',
            'started_at' => now()->subSeconds(75),
            'finished_at' => now(),
        ]);

        Livewire::test(ListDeployments::class)
            ->assertCanSeeTableRecords([$deployment])
            // 7 characters, git's own short-SHA width — the full 40 would
            // swamp the column.
            ->assertTableColumnFormattedStateSet('commit_sha', 'abc123d', $deployment)
            // Computed from started_at/finished_at, not a column.
            ->assertTableColumnStateSet('duration', '1m 15s', $deployment);
    }

    // --- Reference: "POST /apps/:id/rollback creates deployment with rollback_sha" ---

    public function test_rolling_back_creates_a_new_deployment_carrying_the_source_commit_sha(): void
    {
        $source = $this->makeDeployment([
            'commit_sha' => 'abc123def456abc123def456abc123def456abc1',
        ]);

        $component = Livewire::test(ViewDeployment::class, ['record' => $source->getRouteKey()])
            ->callAction('rollback');

        $rollback = Deployment::query()->where('id', '!=', $source->id)->sole();

        $this->assertSame($this->app_->id, $rollback->app_id);
        $this->assertSame(DeploymentStatus::Pending, $rollback->status);
        // The SOURCE's commit, carried onto the new deployment — this is the
        // SHA DeployApp will `git checkout`.
        $this->assertSame('abc123def456abc123def456abc123def456abc1', $rollback->rollback_sha);
        // The source itself is untouched; a rollback is a new deployment, not
        // a mutation of the old one.
        $this->assertSame(DeploymentStatus::Success, $source->fresh()->status);

        Bus::assertDispatched(DeployApp::class, fn (DeployApp $job): bool => $job->deploymentId === $rollback->id);

        $component->assertRedirect("/deployments/{$rollback->id}");
    }

    // --- Reference: "returns 400 when deployment has no commit_sha" ---

    public function test_rollback_is_not_offered_for_a_deployment_with_no_commit_sha(): void
    {
        $source = $this->makeDeployment(['commit_sha' => null]);

        // The reference's 400. There is nothing to check out, so the action
        // must not be reachable.
        Livewire::test(ViewDeployment::class, ['record' => $source->getRouteKey()])
            ->assertActionHidden('rollback');

        $this->assertSame(1, Deployment::query()->count());
    }

    public function test_rollback_is_not_offered_for_a_deployment_that_did_not_succeed(): void
    {
        $source = $this->makeDeployment([
            'status' => DeploymentStatus::Failed,
            'commit_sha' => 'abc123def456abc123def456abc123def456abc1',
        ]);

        // Rolling back TO a failed deploy's commit would redeploy the code
        // that failed. The reference only renders the button on successful
        // rows.
        Livewire::test(ViewDeployment::class, ['record' => $source->getRouteKey()])
            ->assertActionHidden('rollback');
    }

    public function test_rollback_is_offered_for_a_successful_deployment_with_a_commit_sha(): void
    {
        $source = $this->makeDeployment([
            'commit_sha' => 'abc123def456abc123def456abc123def456abc1',
        ]);

        // The positive case for the two hidden-assertions above — without it
        // they would both pass with the action hidden unconditionally.
        Livewire::test(ViewDeployment::class, ['record' => $source->getRouteKey()])
            ->assertActionVisible('rollback');
    }

    public function test_rolling_back_a_deployment_whose_sha_was_cleared_since_the_page_rendered_does_nothing(): void
    {
        $source = $this->makeDeployment([
            'commit_sha' => 'abc123def456abc123def456abc123def456abc1',
        ]);

        $page = Livewire::test(ViewDeployment::class, ['record' => $source->getRouteKey()]);

        // The row changes between the render that drew the button and the
        // click that submits it. Phase 8 deleted RollbackAction's in-action
        // refusal for this case after proving it could not fire; this pins
        // the mechanism that actually does the work — visible(), re-evaluated
        // at callMountedAction() against a freshly-loaded record.
        $source->forceFill(['commit_sha' => null])->save();

        $page->callAction('rollback');

        // No rollback deployment created, and nothing queued to check out a
        // null SHA.
        $this->assertSame(1, Deployment::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_rollback_targets_the_app_the_source_deployment_belongs_to(): void
    {
        $otherApp = App::factory()->create(['name' => 'Other', 'path' => '/repos/other']);
        Deployment::create(['app_id' => $otherApp->id, 'status' => DeploymentStatus::Success]);

        $source = $this->makeDeployment([
            'commit_sha' => 'abc123def456abc123def456abc123def456abc1',
        ]);

        Livewire::test(ViewDeployment::class, ['record' => $source->getRouteKey()])
            ->callAction('rollback');

        // The reference route takes an app id AND a deployment id and 404s
        // when they disagree; here the app is read off the source deployment,
        // so the two cannot disagree. This pins that it reads the SOURCE's
        // app_id rather than, say, the first app in the table.
        $rollback = Deployment::query()->whereNotNull('rollback_sha')->sole();
        $this->assertSame($this->app_->id, $rollback->app_id);
    }
}
