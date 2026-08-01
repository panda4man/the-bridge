<?php

namespace Tests\Feature\Filament;

use App\Enums\DeploymentStatus;
use App\Filament\Resources\Apps\Pages\EditApp;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Filament\Resources\Apps\Pages\ViewApp;
use App\Filament\Resources\Apps\Schemas\AppForm;
use App\Jobs\DeployApp;
use App\Models\App;
use App\Models\Deployment;
use App\Models\User;
use App\Services\Process\ProcessResult;
use App\Services\Process\ProcessRunner;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

/**
 * The app-scoped actions: Deploy, Delete, Regenerate webhook secret, Edit .env.
 *
 * Ports reference/tests/Feature/deployTrigger.test.ts and
 * reference/tests/Feature/envEditor.test.ts, plus the delete side effect from
 * reference/src/routes/apps.ts:132-145.
 */
class AppActionsTest extends TestCase
{
    use RefreshDatabase;

    private string $reposPath;

    private FakeProcessRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        Bus::fake();

        $this->reposPath = sys_get_temp_dir().'/bridge-actions-'.bin2hex(random_bytes(6));
        mkdir($this->reposPath, 0755, true);
        config(['bridge.repos_path' => $this->reposPath]);

        AppForm::forgetBranchCache();

        $this->runner = new FakeProcessRunner;
        $this->app->instance(ProcessRunner::class, $this->runner);
        $this->runner->answerByArgv(static function (array $command): ?ProcessResult {
            if (array_slice($command, 0, 3) === ['git', 'ls-remote', '--heads']) {
                return new ProcessResult(0, "0000000\trefs/heads/main", '');
            }

            if (array_slice($command, 0, 2) === ['docker', 'compose']) {
                return new ProcessResult(0, '', '');
            }

            return null;
        });
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->reposPath);
        AppForm::forgetBranchCache();

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

    /**
     * @return list<list<string>>
     */
    private function composeDowns(): array
    {
        return array_values(array_filter(
            $this->runner->commands(),
            static fn (array $c): bool => array_slice($c, 0, 2) === ['docker', 'compose'] && in_array('down', $c, true),
        ));
    }

    private function makeApp(array $overrides = []): App
    {
        $path = $overrides['path'] ?? $this->reposPath.'/'.bin2hex(random_bytes(4));

        return App::factory()->create(array_merge([
            'name' => 'Actionable',
            'repo_url' => 'https://github.com/x/y.git',
            'branch' => 'main',
        ], $overrides, ['path' => $path]));
    }

    // --- Reference: "POST /apps/:id/deploy creates pending deployment and queues job" ---

    public function test_the_deploy_action_creates_a_pending_deployment_dispatches_it_and_redirects_to_it(): void
    {
        $app = $this->makeApp();

        $component = Livewire::test(ViewApp::class, ['record' => $app->getRouteKey()])
            ->callAction('deploy');

        $deployment = Deployment::query()->sole();

        $this->assertSame($app->id, $deployment->app_id);
        $this->assertSame(DeploymentStatus::Pending, $deployment->status);
        // A plain deploy, not a rollback: a rollback_sha would send the job
        // down its `git checkout` path and disable its auto-rollback guard.
        $this->assertNull($deployment->rollback_sha);

        Bus::assertDispatched(DeployApp::class, fn (DeployApp $job): bool => $job->deploymentId === $deployment->id);

        $component->assertRedirect("/deployments/{$deployment->id}");
    }

    public function test_the_deploy_action_deploys_the_app_it_was_invoked_on(): void
    {
        // Two apps, so `App::first()` and the record actually passed in are
        // distinguishable.
        $other = $this->makeApp(['name' => 'Other']);
        $target = $this->makeApp(['name' => 'Target']);

        Livewire::test(ViewApp::class, ['record' => $target->getRouteKey()])
            ->callAction('deploy');

        $this->assertSame($target->id, Deployment::query()->sole()->app_id);
        $this->assertSame(0, Deployment::query()->where('app_id', $other->id)->count());
    }

    // --- Delete: containers down, then directory, then the row ---

    public function test_deleting_an_app_brings_its_containers_down_and_removes_its_checkout(): void
    {
        $path = $this->reposPath.'/deleteme';
        mkdir($path, 0755, true);
        file_put_contents($path.'/docker-compose.yml', "services: {}\n");

        $app = $this->makeApp(['path' => $path]);

        Livewire::test(EditApp::class, ['record' => $app->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertCount(1, $this->composeDowns());
        $this->assertDirectoryDoesNotExist($path);
        $this->assertNull(App::find($app->id));
    }

    public function test_deleting_an_app_whose_checkout_is_already_gone_still_deletes_the_row(): void
    {
        $app = $this->makeApp(['path' => $this->reposPath.'/never-cloned']);

        Livewire::test(EditApp::class, ['record' => $app->getRouteKey()])
            ->callAction(DeleteAction::class);

        // No directory means nothing to bring down — a missing checkout must
        // not strand the row. (Filtered rather than asserting no processes at
        // all: rendering the edit form lists the remote's branches.)
        $this->assertSame([], $this->composeDowns());
        $this->assertNull(App::find($app->id));
    }

    public function test_the_apps_table_delete_runs_the_same_side_effect(): void
    {
        $path = $this->reposPath.'/table-delete';
        mkdir($path, 0755, true);
        file_put_contents($path.'/docker-compose.yml', "services: {}\n");

        $app = $this->makeApp(['path' => $path]);

        Livewire::test(ListApps::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($app));

        // The row action and the edit-page action are two separate wirings of
        // the same side effect; deleting from the list must not leave a stack
        // running just because it took the other route.
        $this->assertDirectoryDoesNotExist($path);
        $this->assertNull(App::find($app->id));
    }

    // --- Regenerate webhook secret ---

    public function test_regenerating_the_webhook_secret_writes_64_hex_characters(): void
    {
        $app = $this->makeApp(['webhook_secret' => 'old-secret']);

        Livewire::test(EditApp::class, ['record' => $app->getRouteKey()])
            ->callAction('regenerateWebhookSecret');

        $secret = $app->fresh()->webhook_secret;

        $this->assertNotSame('old-secret', $secret);
        // 32 random bytes, hex-encoded. The width is the HMAC key strength
        // Phase 5's webhook signature check depends on.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
    }

    public function test_regenerating_the_webhook_secret_produces_a_different_value_each_time(): void
    {
        $app = $this->makeApp();

        Livewire::test(EditApp::class, ['record' => $app->getRouteKey()])
            ->callAction('regenerateWebhookSecret');
        $first = $app->fresh()->webhook_secret;

        Livewire::test(EditApp::class, ['record' => $app->fresh()->getRouteKey()])
            ->callAction('regenerateWebhookSecret');
        $second = $app->fresh()->webhook_secret;

        $this->assertNotSame($first, $second);
    }

    // --- Reference: envEditor.test.ts ---

    public function test_the_env_editor_opens_prefilled_with_the_current_file(): void
    {
        $path = $this->reposPath.'/envapp';
        mkdir($path, 0755, true);
        file_put_contents($path.'/.env', "EXISTING=1\n");

        $app = $this->makeApp(['path' => $path]);

        Livewire::test(ViewApp::class, ['record' => $app->getRouteKey()])
            ->mountAction('editEnvFile')
            ->assertActionDataSet(['content' => "EXISTING=1\n"]);
    }

    public function test_the_env_editor_writes_non_empty_content(): void
    {
        $path = $this->reposPath.'/envwrite';
        mkdir($path, 0755, true);
        file_put_contents($path.'/.env', "OLD=1\n");

        $app = $this->makeApp(['path' => $path]);

        Livewire::test(ViewApp::class, ['record' => $app->getRouteKey()])
            ->callAction('editEnvFile', ['content' => "NEW=1\n"]);

        $this->assertSame("NEW=1\n", file_get_contents($path.'/.env'));
    }

    public function test_the_env_editor_rejects_empty_content_and_leaves_the_file_untouched(): void
    {
        $path = $this->reposPath.'/envempty';
        mkdir($path, 0755, true);
        file_put_contents($path.'/.env', "EXISTING=1\n");

        $app = $this->makeApp(['path' => $path]);

        Livewire::test(ViewApp::class, ['record' => $app->getRouteKey()])
            ->callAction('editEnvFile', ['content' => ''])
            ->assertHasActionErrors(['content']);

        // The refusal is only worth anything if the file survives it — an
        // accidental select-all-delete on a production .env is unrecoverable.
        $this->assertSame("EXISTING=1\n", file_get_contents($path.'/.env'));
    }
}
