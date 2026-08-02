<?php

namespace Tests\Feature\Filament;

use App\Models\App;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_page_is_the_apps_list_and_lists_apps(): void
    {
        $this->actingAs(User::factory()->create());

        App::factory()->create(['name' => 'Listed App', 'path' => '/repos/listed']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Listed App');
    }

    public function test_the_panel_requires_authentication(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    /**
     * The reference asserted `GET /apps/create`, `GET /apps/:id` and
     * `GET /apps/:id/edit` return 200 (appCrud.test.ts). Every other panel test
     * mounts these pages through `Livewire::test()`, which bypasses routing —
     * so without these three the URLs themselves are unpinned, and the Phase 4
     * note about a colliding `web.php` route silently erasing a panel route
     * name would have nothing to catch it.
     */
    public function test_the_apps_crud_pages_resolve_and_render(): void
    {
        $this->actingAs(User::factory()->create());

        $app = App::factory()->create(['name' => 'Detailed App', 'path' => '/repos/detailed']);

        // Each asserts content only the panel's own page renders. A 200 alone
        // would also be satisfied by a colliding route in web.php replacing the
        // page with something else entirely — which is the failure mode these
        // exist to catch, and `assertOk()` walks straight past it.
        $this->get('/apps/create')
            ->assertOk()
            ->assertSee('Repo URL');

        $this->get("/apps/{$app->getRouteKey()}")
            ->assertOk()
            ->assertSee('Detailed App');

        $this->get("/apps/{$app->getRouteKey()}/edit")
            ->assertOk()
            ->assertSee('Detailed App')
            ->assertSee('Repo URL');
    }

    public function test_the_apps_crud_pages_require_authentication(): void
    {
        $app = App::factory()->create(['path' => '/repos/guarded']);

        $this->get('/apps/create')->assertRedirect('/login');
        $this->get("/apps/{$app->getRouteKey()}")->assertRedirect('/login');
        $this->get("/apps/{$app->getRouteKey()}/edit")->assertRedirect('/login');
    }
}
