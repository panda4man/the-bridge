<?php

namespace Tests\Feature\Filament;

use App\Enums\DeploymentStatus;
use App\Filament\Resources\Apps\Pages\EditApp;
use App\Filament\Resources\Apps\Schemas\AppForm;
use App\Jobs\DeployApp;
use App\Models\App;
use App\Models\Deployment;
use App\Models\User;
use App\Services\Process\ProcessResult;
use App\Services\Process\ProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

/**
 * Ported from the update/delete half of
 * reference/tests/Feature/appCrud.test.ts, plus the two side effects the
 * reference route has that plain CRUD does not: a branch change queues a
 * deploy (apps.ts:119-126), and a delete brings containers down and removes
 * the checkout (apps.ts:132-145).
 *
 * Bus::fake() throughout. QUEUE_CONNECTION is `sync` in phpunit.xml, so a real
 * DeployApp::dispatch() would run an entire deploy in-process against the
 * fake runner — see the note in tests/Unit/DeployAppTest.php's docblock. Here
 * the dispatch itself is the behaviour under test, so faking the bus both
 * prevents that and is what makes the assertion possible.
 */
class EditAppTest extends TestCase
{
    use RefreshDatabase;

    private string $reposPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        Bus::fake();

        $this->reposPath = sys_get_temp_dir().'/bridge-repos-'.bin2hex(random_bytes(6));
        mkdir($this->reposPath, 0755, true);
        config(['bridge.repos_path' => $this->reposPath]);

        AppForm::forgetBranchCache();
        $this->fakeGit();
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
     * @param  list<string>  $branches
     */
    private function fakeGit(array $branches = ['main', 'develop']): FakeProcessRunner
    {
        $runner = new FakeProcessRunner;
        $this->app->instance(ProcessRunner::class, $runner);

        $runner->answerByArgv(function (array $command) use ($branches): ?ProcessResult {
            if (array_slice($command, 0, 3) === ['git', 'ls-remote', '--heads']) {
                $lines = array_map(static fn (string $b): string => "0000000\trefs/heads/{$b}", $branches);

                return new ProcessResult(0, implode("\n", $lines), '');
            }

            if (array_slice($command, 0, 2) === ['docker', 'compose']) {
                return new ProcessResult(0, '', '');
            }

            return null;
        });

        return $runner;
    }

    private function makeApp(array $overrides = []): App
    {
        return App::factory()->create(array_merge([
            'name' => 'Old',
            'repo_url' => 'https://github.com/x/y.git',
            'branch' => 'main',
            'path' => $this->reposPath.'/existing',
        ], $overrides));
    }

    private function edit(App $app): Testable
    {
        return Livewire::test(EditApp::class, ['record' => $app->getRouteKey()]);
    }

    // --- Reference: "PUT /apps/:id updates app and redirects" ---

    public function test_updates_the_app_and_redirects_back_to_it(): void
    {
        $app = $this->makeApp();

        $this->edit($app)
            ->fillForm(['name' => 'Updated'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect("/apps/{$app->id}");

        $this->assertSame('Updated', $app->fresh()->name);
    }

    public function test_an_unchanged_branch_queues_no_deployment(): void
    {
        $app = $this->makeApp();

        $this->edit($app)
            ->fillForm(['name' => 'Renamed Only'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, Deployment::query()->count());
        Bus::assertNotDispatched(DeployApp::class);
    }

    // --- The update side effect: a changed branch deploys it ---

    public function test_changing_the_branch_queues_a_deployment_and_redirects_to_it(): void
    {
        $app = $this->makeApp(['branch' => 'main']);

        $component = $this->edit($app)
            ->fillForm(['branch' => 'develop'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('develop', $app->fresh()->branch);

        $deployment = Deployment::query()->sole();
        $this->assertSame($app->id, $deployment->app_id);
        $this->assertSame(DeploymentStatus::Pending, $deployment->status);
        // A branch change is a fresh deploy of a branch tip, not a rollback —
        // a rollback_sha here would send the job down its checkout path and
        // suppress the auto-rollback guard.
        $this->assertNull($deployment->rollback_sha);

        Bus::assertDispatched(DeployApp::class, fn (DeployApp $job): bool => $job->deploymentId === $deployment->id);

        // Redirects to the DEPLOYMENT, not back to the app — that is where
        // the operator watches the deploy they just triggered.
        $component->assertRedirect("/deployments/{$deployment->id}");
    }

    // --- deploy_steps: JSON column <-> plain-text textarea ---

    public function test_the_deploy_steps_textarea_is_filled_from_the_json_column(): void
    {
        $app = $this->makeApp([
            'deploy_steps' => json_encode([
                ['service' => 'app', 'run' => 'php artisan migrate --force'],
                ['service' => 'web', 'run' => 'nginx -s reload'],
            ]),
        ]);

        $this->edit($app)->assertFormSet([
            'deploy_steps_text' => "app: php artisan migrate --force\nweb: nginx -s reload",
        ]);
    }

    public function test_the_deploy_steps_textarea_is_parsed_back_into_the_json_column(): void
    {
        $app = $this->makeApp();

        $this->edit($app)
            ->fillForm(['deploy_steps_text' => "app: php artisan cache:clear\n"])
            ->call('save')
            ->assertHasNoFormErrors();

        // Split on the FIRST colon only, so `cache:clear` survives — the
        // Phase 2 parity rule this column depends on.
        $this->assertSame(
            [['service' => 'app', 'run' => 'php artisan cache:clear']],
            json_decode($app->fresh()->deploy_steps, true),
        );
    }

    public function test_clearing_the_deploy_steps_textarea_nulls_the_column_rather_than_storing_an_empty_array(): void
    {
        $app = $this->makeApp([
            'deploy_steps' => json_encode([['service' => 'app', 'run' => 'echo hi']]),
        ]);

        $this->edit($app)
            ->fillForm(['deploy_steps_text' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        // Not '[]'. DeploySteps::resolve() reports source 'none' for NULL and
        // the API renders the column directly.
        $this->assertNull($app->fresh()->deploy_steps);
    }

    public function test_clearing_the_health_url_nulls_the_column_rather_than_storing_an_empty_string(): void
    {
        $app = $this->makeApp(['health_url' => 'https://example.test/up']);

        $this->edit($app)
            ->fillForm(['health_url' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        // An empty string is a URL the health poller would dutifully poll.
        $this->assertNull($app->fresh()->health_url);
    }

    // --- The `..` check the reference applies on create but NOT on update ---

    public function test_a_parent_directory_traversal_is_rejected_on_update_too(): void
    {
        $app = $this->makeApp();

        $component = $this->edit($app)
            ->fillForm(['path' => '/repos/../etc/passwd'])
            ->call('save');

        $component->assertHasFormErrors(['path']);
        $this->assertSame(
            'Path must not contain ..',
            $component->instance()->getErrorBag()->first('data.path'),
        );
        $this->assertSame($this->reposPath.'/existing', $app->fresh()->path);
    }
}
