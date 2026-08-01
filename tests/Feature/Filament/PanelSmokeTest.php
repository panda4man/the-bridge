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
}
