<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * QC B10: config/bridge.php's `preg_replace('#/$#', '', env('BRIDGE_REPOS_PATH', '/repos'))`
 * had zero coverage — reverting it to plain `env('BRIDGE_REPOS_PATH', '/repos')`
 * left the full suite green, because nothing exercises config/bridge.php's
 * `repos_path` key directly (every service test that touches the filesystem
 * builds its own explicit temp path rather than going through config()).
 *
 * config/bridge.php is a plain PHP file returning an array — re-`require`ing
 * it after changing the env var re-evaluates env() fresh, so this tests the
 * real file rather than reimplementing its regex. putenv() alone is not
 * reliably picked up by Laravel's env() helper (its default repository
 * adapter chain also consults $_ENV/$_SERVER), so all three are set/restored
 * together.
 *
 * Semantics mirror reference/src/validators/appValidators.ts:9's
 * `.replace(/\/$/, '')` exactly: a SINGLE trailing slash is stripped, so
 * `/repos//` only loses one and `/` collapses to `''` — this port does not
 * "fix" that edge case, it reproduces it.
 */
class ConfigReposPathTest extends TestCase
{
    private ?string $previousEnv = null;

    private bool $hadPreviousEnv = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadPreviousEnv = array_key_exists('BRIDGE_REPOS_PATH', $_ENV);
        $this->previousEnv = getenv('BRIDGE_REPOS_PATH') ?: null;
    }

    protected function tearDown(): void
    {
        $this->setReposPathEnv($this->hadPreviousEnv ? ($this->previousEnv ?? '') : false);
        parent::tearDown();
    }

    private function setReposPathEnv(string|false $value): void
    {
        if ($value === false) {
            putenv('BRIDGE_REPOS_PATH');
            unset($_ENV['BRIDGE_REPOS_PATH'], $_SERVER['BRIDGE_REPOS_PATH']);

            return;
        }

        putenv("BRIDGE_REPOS_PATH={$value}");
        $_ENV['BRIDGE_REPOS_PATH'] = $value;
        $_SERVER['BRIDGE_REPOS_PATH'] = $value;
    }

    private function reposPathFor(string $envValue): string
    {
        $this->setReposPathEnv($envValue);
        $config = require base_path('config/bridge.php');

        return $config['repos_path'];
    }

    public function test_strips_a_single_trailing_slash(): void
    {
        $this->assertSame('/repos', $this->reposPathFor('/repos/'));
    }

    public function test_leaves_a_path_without_a_trailing_slash_untouched(): void
    {
        $this->assertSame('/repos', $this->reposPathFor('/repos'));
    }

    public function test_double_trailing_slash_loses_only_one(): void
    {
        $this->assertSame('/repos/', $this->reposPathFor('/repos//'));
    }

    public function test_a_single_slash_collapses_to_empty_string(): void
    {
        $this->assertSame('', $this->reposPathFor('/'));
    }

    public function test_double_slash_collapses_to_a_single_slash(): void
    {
        $this->assertSame('/', $this->reposPathFor('//'));
    }

    public function test_falls_back_to_the_literal_default_when_unset(): void
    {
        $this->setReposPathEnv(false);
        $config = require base_path('config/bridge.php');

        $this->assertSame('/repos', $config['repos_path']);
    }
}
