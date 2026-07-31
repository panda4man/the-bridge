<?php

namespace Tests\Unit;

use App\Enums\AppStatus;
use App\Models\App;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ported from reference/tests/Unit/models.test.ts ("App model" describe
 * block), case for case.
 */
class AppModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_inserts_an_app_and_returns_it_with_id(): void
    {
        $app = App::create([
            'name' => 'Test App',
            'repo_url' => 'https://github.com/x/y.git',
            'branch' => 'main',
            'path' => '/tmp/test',
        ]);

        $this->assertNotNull($app->id);
        $this->assertSame('Test App', $app->name);
        $this->assertSame(AppStatus::Idle, $app->status);
    }

    public function test_find_returns_the_app(): void
    {
        $created = App::create([
            'name' => 'Find Me',
            'repo_url' => 'https://x.com/r.git',
            'branch' => 'main',
            'path' => '/tmp/find',
        ]);

        $found = App::find($created->id);

        $this->assertSame('Find Me', $found->name);
    }

    public function test_find_returns_null_for_unknown_id(): void
    {
        $this->assertNull(App::find(9999));
    }

    public function test_list_returns_all_apps_newest_first(): void
    {
        App::create(['name' => 'A', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/a']);
        App::create(['name' => 'B', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/b']);

        $apps = App::query()->orderByDesc('id')->get();

        $this->assertGreaterThanOrEqual(2, $apps->count());
        $this->assertSame('B', $apps->first()->name);
    }

    public function test_update_changes_fields_and_returns_updated_app(): void
    {
        $app = App::create(['name' => 'Old', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/old']);

        $app->update(['name' => 'New', 'repo_url' => 'r', 'branch' => 'develop', 'path' => '/new']);

        $this->assertSame('New', $app->fresh()->name);
        $this->assertSame('develop', $app->fresh()->branch);
    }

    public function test_update_status_changes_status(): void
    {
        $app = App::create(['name' => 'Status', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/s']);

        $app->update(['status' => AppStatus::Deploying]);

        $this->assertSame(AppStatus::Deploying, $app->fresh()->status);
    }

    public function test_remove_deletes_the_app(): void
    {
        $app = App::create(['name' => 'Gone', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/gone']);

        $app->delete();

        $this->assertNull(App::find($app->id));
    }
}
