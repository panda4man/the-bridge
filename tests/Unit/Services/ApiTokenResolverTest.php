<?php

namespace Tests\Unit\Services;

use App\Models\Setting;
use App\Services\ApiTokenResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ported from reference/src/middleware/apiAuth.ts:5-10 (resolveToken()).
 * See config/bridge.php's 'api_token' block comment for why the DB
 * fallback can't live in config directly.
 */
class ApiTokenResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_when_neither_layer_is_set(): void
    {
        config(['bridge.api_token' => '']);

        $this->assertNull((new ApiTokenResolver)->resolve());
    }

    public function test_env_layer_is_used_when_non_empty(): void
    {
        config(['bridge.api_token' => 'env-token']);

        $this->assertSame('env-token', (new ApiTokenResolver)->resolve());
    }

    public function test_env_layer_is_trimmed(): void
    {
        config(['bridge.api_token' => '  env-token  ']);

        $this->assertSame('env-token', (new ApiTokenResolver)->resolve());
    }

    public function test_falls_back_to_settings_table_when_env_is_empty(): void
    {
        config(['bridge.api_token' => '']);
        Setting::setValue('api_token', 'settings-token');

        $this->assertSame('settings-token', (new ApiTokenResolver)->resolve());
    }

    public function test_settings_layer_is_trimmed(): void
    {
        config(['bridge.api_token' => '']);
        Setting::setValue('api_token', '  settings-token  ');

        $this->assertSame('settings-token', (new ApiTokenResolver)->resolve());
    }

    public function test_env_layer_wins_over_settings_when_both_are_set(): void
    {
        config(['bridge.api_token' => 'env-token']);
        Setting::setValue('api_token', 'settings-token');

        $this->assertSame('env-token', (new ApiTokenResolver)->resolve());
    }

    public function test_returns_null_when_settings_value_is_only_whitespace(): void
    {
        config(['bridge.api_token' => '']);
        Setting::setValue('api_token', '   ');

        $this->assertNull((new ApiTokenResolver)->resolve());
    }

    public function test_returns_null_when_env_is_only_whitespace_and_settings_unset(): void
    {
        config(['bridge.api_token' => '   ']);

        $this->assertNull((new ApiTokenResolver)->resolve());
    }
}
