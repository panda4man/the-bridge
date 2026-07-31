<?php

namespace Tests\Unit;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Not part of the reference's Unit suite (settings only appears in the
 * reference's Feature/settings.test.ts, an HTTP-level test out of Phase 1
 * scope — see reference/src/models/settings.ts for the ported get()/set()
 * pair). Added for basic coverage of the model created in this phase.
 */
class SettingModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_value_returns_null_when_unset(): void
    {
        $this->assertNull(Setting::getValue('missing'));
    }

    public function test_set_value_then_get_value_round_trips(): void
    {
        Setting::setValue('api_token', 'secret-123');

        $this->assertSame('secret-123', Setting::getValue('api_token'));
    }

    public function test_set_value_upserts_on_conflict(): void
    {
        Setting::setValue('api_token', 'first');
        Setting::setValue('api_token', 'second');

        $this->assertSame('second', Setting::getValue('api_token'));
        $this->assertSame(1, Setting::query()->where('key', 'api_token')->count());
    }
}
