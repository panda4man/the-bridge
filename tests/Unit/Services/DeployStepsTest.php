<?php

namespace Tests\Unit\Services;

use App\Models\App;
use App\Services\DeploySteps;
use RuntimeException;
use Tests\TestCase;

/**
 * Ported from reference/src/services/deploySteps.test.ts, case for case —
 * 24 cases: 12 resolveDeploySteps, 8 parseUiSteps, 4 serialize/parse
 * round-trip.
 *
 * Uses App::factory()->make() (unsaved) rather than persisting to the DB —
 * DeploySteps::resolve() only ever reads $app->path and $app->deploy_steps
 * off the in-memory instance, so no database round trip is needed.
 */
class DeployStepsTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir().'/deploy-steps-'.bin2hex(random_bytes(8));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeApp(array $overrides = []): App
    {
        return App::factory()->make(array_merge([
            'path' => $this->workDir,
            'deploy_steps' => null,
        ], $overrides));
    }

    // --- resolveDeploySteps ---

    public function test_returns_none_when_no_bridge_yml_and_no_deploy_steps(): void
    {
        $result = DeploySteps::resolve($this->makeApp());
        $this->assertSame(['steps' => [], 'source' => 'none'], $result);
    }

    public function test_repo_file_takes_precedence_over_ui_steps(): void
    {
        file_put_contents(
            $this->workDir.'/bridge.yml',
            "post_deploy:\n  - service: app\n    run: php artisan migrate --force\n"
        );
        $uiSteps = json_encode([['service' => 'worker', 'run' => 'restart']]);
        $app = $this->makeApp(['deploy_steps' => $uiSteps]);

        $result = DeploySteps::resolve($app);

        $this->assertSame('repo', $result['source']);
        $this->assertSame([['service' => 'app', 'run' => 'php artisan migrate --force']], $result['steps']);
    }

    public function test_falls_through_to_ui_steps_when_bridge_yml_is_absent(): void
    {
        $uiSteps = json_encode([['service' => 'worker', 'run' => 'queue:restart']]);
        $app = $this->makeApp(['deploy_steps' => $uiSteps]);

        $result = DeploySteps::resolve($app);

        $this->assertSame('ui', $result['source']);
        $this->assertSame([['service' => 'worker', 'run' => 'queue:restart']], $result['steps']);
    }

    public function test_returns_none_when_ui_steps_is_empty_json_array(): void
    {
        $app = $this->makeApp(['deploy_steps' => '[]']);

        $result = DeploySteps::resolve($app);

        $this->assertSame(['steps' => [], 'source' => 'none'], $result);
    }

    public function test_parses_bridge_yml_with_multiple_steps(): void
    {
        file_put_contents(
            $this->workDir.'/bridge.yml',
            "post_deploy:\n  - service: app\n    run: php artisan migrate --force\n  - service: app\n    run: php artisan config:cache\n"
        );

        $result = DeploySteps::resolve($this->makeApp());

        $this->assertSame('repo', $result['source']);
        $this->assertCount(2, $result['steps']);
        $this->assertSame(['service' => 'app', 'run' => 'php artisan migrate --force'], $result['steps'][0]);
        $this->assertSame(['service' => 'app', 'run' => 'php artisan config:cache'], $result['steps'][1]);
    }

    public function test_throws_on_malformed_bridge_yml_invalid_yaml(): void
    {
        file_put_contents($this->workDir.'/bridge.yml', "post_deploy: [:::\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/bridge\.yml parse error/');
        DeploySteps::resolve($this->makeApp());
    }

    public function test_throws_on_bridge_yml_with_post_deploy_not_an_array(): void
    {
        file_put_contents($this->workDir.'/bridge.yml', "post_deploy: \"not an array\"\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/post_deploy array/');
        DeploySteps::resolve($this->makeApp());
    }

    public function test_throws_on_bridge_yml_entry_with_empty_service(): void
    {
        file_put_contents(
            $this->workDir.'/bridge.yml',
            "post_deploy:\n  - service: \"\"\n    run: do something\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/service must be a non-empty string/');
        DeploySteps::resolve($this->makeApp());
    }

    public function test_throws_on_bridge_yml_entry_with_empty_run(): void
    {
        file_put_contents(
            $this->workDir.'/bridge.yml',
            "post_deploy:\n  - service: app\n    run: \"\"\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/run must be a non-empty string/');
        DeploySteps::resolve($this->makeApp());
    }

    public function test_throws_on_malformed_json_in_deploy_steps(): void
    {
        $app = $this->makeApp(['deploy_steps' => 'not-json']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/deploy_steps JSON parse error/');
        DeploySteps::resolve($app);
    }

    public function test_throws_on_deploy_steps_entry_with_missing_service(): void
    {
        $app = $this->makeApp(['deploy_steps' => json_encode([['run' => 'migrate']])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/service must be a non-empty string/');
        DeploySteps::resolve($app);
    }

    public function test_throws_on_deploy_steps_entry_with_missing_run(): void
    {
        $app = $this->makeApp(['deploy_steps' => json_encode([['service' => 'app']])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/run must be a non-empty string/');
        DeploySteps::resolve($app);
    }

    // --- parseUiSteps ---

    public function test_parses_a_simple_service_command_line(): void
    {
        $this->assertSame(
            [['service' => 'app', 'run' => 'php artisan migrate']],
            DeploySteps::parseUiSteps('app: php artisan migrate')
        );
    }

    public function test_splits_on_first_colon_only_commands_keep_their_colons(): void
    {
        $this->assertSame(
            [['service' => 'app', 'run' => 'php artisan cache:clear']],
            DeploySteps::parseUiSteps('app: php artisan cache:clear')
        );
    }

    public function test_handles_rails_db_migrate(): void
    {
        $this->assertSame(
            [['service' => 'web', 'run' => 'rails db:migrate']],
            DeploySteps::parseUiSteps('web: rails db:migrate')
        );
    }

    public function test_skips_blank_lines(): void
    {
        $text = "app: migrate\n\nworker: restart\n";

        $this->assertSame([
            ['service' => 'app', 'run' => 'migrate'],
            ['service' => 'worker', 'run' => 'restart'],
        ], DeploySteps::parseUiSteps($text));
    }

    public function test_skips_lines_without_a_colon(): void
    {
        $text = "no colon here\napp: migrate";

        $this->assertSame([['service' => 'app', 'run' => 'migrate']], DeploySteps::parseUiSteps($text));
    }

    public function test_skips_lines_with_empty_run_after_colon(): void
    {
        $this->assertSame([], DeploySteps::parseUiSteps('app:'));
    }

    public function test_returns_empty_array_for_blank_input(): void
    {
        $this->assertSame([], DeploySteps::parseUiSteps(''));
        $this->assertSame([], DeploySteps::parseUiSteps("   \n  \n"));
    }

    public function test_trims_whitespace_from_service_and_run(): void
    {
        $this->assertSame(
            [['service' => 'app', 'run' => 'php artisan migrate']],
            DeploySteps::parseUiSteps('  app  :  php artisan migrate  ')
        );
    }

    // --- serializeUiSteps / parseUiSteps round-trip ---

    public function test_round_trips_a_single_step(): void
    {
        $steps = [['service' => 'app', 'run' => 'php artisan migrate --force']];
        $this->assertSame($steps, DeploySteps::parseUiSteps(DeploySteps::serializeUiSteps($steps)));
    }

    public function test_round_trips_steps_with_colons_in_commands(): void
    {
        $steps = [
            ['service' => 'app', 'run' => 'php artisan cache:clear'],
            ['service' => 'web', 'run' => 'rails db:migrate'],
        ];
        $this->assertSame($steps, DeploySteps::parseUiSteps(DeploySteps::serializeUiSteps($steps)));
    }

    public function test_round_trips_multiple_steps(): void
    {
        $steps = [
            ['service' => 'app', 'run' => 'php artisan migrate --force'],
            ['service' => 'app', 'run' => 'php artisan config:cache'],
            ['service' => 'worker', 'run' => 'queue:restart'],
        ];
        $this->assertSame($steps, DeploySteps::parseUiSteps(DeploySteps::serializeUiSteps($steps)));
    }

    public function test_round_trips_empty_array(): void
    {
        $this->assertSame([], DeploySteps::parseUiSteps(DeploySteps::serializeUiSteps([])));
    }
}
