<?php

namespace Tests\Feature\Filament;

use App\Enums\DeploymentStatus;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Models\App;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The App index's "Last Deploy" column and its default sort.
 */
class AppsTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function deploymentAt(App $app, \DateTimeInterface $createdAt): Deployment
    {
        $deployment = Deployment::create([
            'app_id' => $app->id,
            'status' => DeploymentStatus::Success,
        ]);

        // created_at isn't mass-assignable (see Deployment's #[Fillable]), so
        // back-date it directly to simulate deploys that happened at
        // different times.
        $deployment->forceFill(['created_at' => $createdAt])->save();

        return $deployment;
    }

    public function test_the_index_shows_each_apps_most_recent_deploy_timestamp(): void
    {
        $app = App::factory()->create(['name' => 'A', 'path' => '/repos/a']);

        $this->deploymentAt($app, now()->subDay());
        $latest = $this->deploymentAt($app, now());

        Livewire::test(ListApps::class)
            ->assertCanSeeTableRecords([$app])
            ->assertTableColumnStateSet('latestDeployment.created_at', $latest->created_at, $app);
    }

    public function test_an_app_with_no_deployments_shows_a_placeholder_instead_of_a_timestamp(): void
    {
        $app = App::factory()->create(['name' => 'No Deploys', 'path' => '/repos/none']);

        Livewire::test(ListApps::class)
            ->assertTableColumnStateSet('latestDeployment.created_at', null, $app);
    }

    public function test_apps_are_sorted_by_most_recent_deploy_descending_by_default(): void
    {
        $stale = App::factory()->create(['name' => 'Stale', 'path' => '/repos/stale']);
        $fresh = App::factory()->create(['name' => 'Fresh', 'path' => '/repos/fresh']);

        // Deliberately created out of deploy-recency order, so a passing
        // assertion can't be explained by insertion order or by id order.
        $this->deploymentAt($stale, now()->subDays(3));
        $this->deploymentAt($fresh, now());

        Livewire::test(ListApps::class)
            ->assertCanSeeTableRecords([$fresh, $stale], inOrder: true);
    }
}
