<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Apps\Pages\CreateApp;
use App\Filament\Resources\Apps\Schemas\AppForm;
use App\Models\App;
use App\Models\User;
use App\Services\Process\ProcessResult;
use App\Services\Process\ProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

/**
 * Ported from the create half of reference/tests/Feature/appCrud.test.ts.
 *
 * Every git call goes through Tests\Support\FakeProcessRunner in its
 * argv-addressed mode (see answerByArgv()) rather than its queue mode: the
 * branch Select re-renders — and so re-runs `git ls-remote` — an
 * implementation-defined number of times per Livewire interaction, and pinning
 * that count would pin Filament's render behaviour rather than this app's.
 *
 * The repos directory is a real temp directory per test. The directory-state
 * rules ARE the behaviour under test here, so faking the filesystem out would
 * leave nothing to check; Phase 2 set the same precedent for DeploySteps and
 * PortBindings.
 */
class CreateAppTest extends TestCase
{
    use RefreshDatabase;

    private string $reposPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->reposPath = sys_get_temp_dir().'/bridge-repos-'.bin2hex(random_bytes(6));
        mkdir($this->reposPath, 0755, true);
        config(['bridge.repos_path' => $this->reposPath]);

        AppForm::forgetBranchCache();
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
     * @param  list<string>  $branches  What `git ls-remote --heads` reports.
     * @param  ?string  $cloneError  stderr of a failing `git clone`, or null to
     *                               let the clone succeed (creating the
     *                               directory, as a real clone would).
     */
    private function fakeGit(array $branches = ['main'], ?string $cloneError = null): FakeProcessRunner
    {
        $runner = new FakeProcessRunner;
        $this->app->instance(ProcessRunner::class, $runner);

        $runner->answerByArgv(function (array $command) use ($branches, $cloneError): ?ProcessResult {
            if (array_slice($command, 0, 3) === ['git', 'ls-remote', '--heads']) {
                $lines = array_map(static fn (string $b): string => "0000000\trefs/heads/{$b}", $branches);

                return new ProcessResult(0, implode("\n", $lines), '');
            }

            if (($command[1] ?? null) === 'clone') {
                if ($cloneError !== null) {
                    return new ProcessResult(128, '', $cloneError);
                }

                // A real clone leaves a working copy behind; the .env seeding
                // step that runs immediately after depends on it existing.
                // GitService::clone() builds
                // ['git','clone','--branch',$b,'-c','safe.directory=*',$url,$target]
                $target = $command[array_key_last($command)];
                mkdir($target.'/.git', 0755, true);

                return new ProcessResult(0, '', '');
            }

            return null;
        });

        return $runner;
    }

    private function createApp(array $data, array $branches = ['main'], ?string $cloneError = null): Testable
    {
        $this->fakeGit($branches, $cloneError);

        return Livewire::test(CreateApp::class)
            ->fillForm($data)
            ->call('create');
    }

    /**
     * The exact user-facing message, not merely "the field errored".
     *
     * The six directory/path messages are a parity contract with
     * reference/src/validators/appValidators.ts — an operator reads them to
     * work out which of two opposite rules they tripped ("does not exist" vs
     * "already exists"), so a test that accepts any error would pass with the
     * two swapped.
     */
    private function assertFormError(Testable $component, string $field, string $message): void
    {
        $component->assertHasFormErrors([$field]);

        $this->assertSame(
            $message,
            $component->instance()->getErrorBag()->first("data.{$field}"),
        );
    }

    // --- Reference: "POST /apps creates app and redirects" ---

    public function test_creates_the_app_clones_it_and_stores_the_absolute_path(): void
    {
        $this->createApp([
            'name' => 'My App',
            'repo_url' => 'https://github.com/x/y.git',
            'branch' => 'main',
            'path' => 'my-app',
        ])->assertHasNoFormErrors();

        $app = App::query()->where('name', 'My App')->first();

        $this->assertNotNull($app);
        // The form takes a RELATIVE segment; the column stores the absolute
        // path. Getting this backwards would make every later `docker compose
        // -f {path}/docker-compose.yml` resolve against the worker's cwd.
        $this->assertSame($this->reposPath.'/my-app', $app->path);
        $this->assertSame('main', $app->branch);
        $this->assertDirectoryExists($this->reposPath.'/my-app');
    }

    // --- Reference: "POST /apps validates required fields" ---

    public function test_requires_name_repo_url_branch_and_path(): void
    {
        $this->createApp([
            'name' => '',
            'repo_url' => '',
            'branch' => '',
            'path' => '',
        ])->assertHasFormErrors(['name', 'repo_url', 'branch', 'path']);

        $this->assertSame(0, App::query()->count());
    }

    // --- Reference: "POST /apps with skip_clone imports existing git repo" ---

    public function test_skip_clone_imports_an_existing_checkout_without_cloning(): void
    {
        mkdir($this->reposPath.'/imported/.git', 0755, true);

        $runner = $this->fakeGit();

        Livewire::test(CreateApp::class)
            ->fillForm([
                'name' => 'Imported',
                'repo_url' => 'https://github.com/x/y.git',
                'branch' => 'main',
                'path' => 'imported',
                'skip_clone' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNotNull(App::query()->where('name', 'Imported')->first());

        // The whole point of skip_clone: no clone was attempted.
        $clones = array_filter($runner->commands(), static fn (array $c): bool => ($c[1] ?? null) === 'clone');
        $this->assertCount(0, $clones);
    }

    // --- Reference: "POST /apps with skip_clone fails if directory does not exist" ---

    public function test_skip_clone_rejects_a_directory_that_does_not_exist(): void
    {
        $this->assertFormError($this->createApp([
            'name' => 'Missing',
            'repo_url' => 'https://github.com/x/y.git',
            'branch' => 'main',
            'path' => 'nonexistent-99xyz',
            'skip_clone' => true,
        ]), 'path', 'Directory does not exist at this path.');

        $this->assertNull(App::query()->where('name', 'Missing')->first());
    }

    public function test_skip_clone_rejects_a_directory_that_is_not_a_git_repository(): void
    {
        mkdir($this->reposPath.'/not-a-repo', 0755, true);

        $this->assertFormError($this->createApp([
            'name' => 'NotARepo',
            'repo_url' => 'https://github.com/x/y.git',
            'branch' => 'main',
            'path' => 'not-a-repo',
            'skip_clone' => true,
        ]), 'path', 'Directory exists but is not a git repository.');

        $this->assertNull(App::query()->where('name', 'NotARepo')->first());
    }

    public function test_cloning_rejects_a_path_whose_directory_already_exists(): void
    {
        mkdir($this->reposPath.'/occupied', 0755, true);

        $this->assertFormError($this->createApp([
            'name' => 'Occupied',
            'repo_url' => 'https://github.com/x/y.git',
            'branch' => 'main',
            'path' => 'occupied',
        ]), 'path', 'Directory already exists on disk.');

        $this->assertNull(App::query()->where('name', 'Occupied')->first());
    }

    public function test_rejects_a_path_already_used_by_another_app(): void
    {
        App::factory()->create(['path' => $this->reposPath.'/taken']);

        $this->assertFormError($this->createApp([
            'name' => 'Duplicate',
            'repo_url' => 'https://github.com/x/y.git',
            'branch' => 'main',
            'path' => 'taken',
        ]), 'path', 'An app already uses this path.');

        $this->assertNull(App::query()->where('name', 'Duplicate')->first());
    }

    public function test_rejects_a_path_containing_a_parent_directory_traversal(): void
    {
        $this->assertFormError($this->createApp([
            'name' => 'Traversal',
            'repo_url' => 'https://github.com/x/y.git',
            'branch' => 'main',
            'path' => '../escape',
        ]), 'path', 'Path must not contain ..');

        $this->assertNull(App::query()->where('name', 'Traversal')->first());
    }

    // --- Reference: "POST /apps copies .env.example to .env when .env is absent" ---

    public function test_seeds_env_from_env_example_when_env_is_absent(): void
    {
        $full = $this->reposPath.'/env-copy';
        mkdir($full.'/.git', 0755, true);
        file_put_contents($full.'/.env.example', "APP_KEY=example\n");

        $this->createApp([
            'name' => 'EnvCopy',
            'repo_url' => 'https://github.com/x/y.git',
            'branch' => 'main',
            'path' => 'env-copy',
            'skip_clone' => true,
        ])->assertHasNoFormErrors();

        $this->assertFileExists($full.'/.env');
        $this->assertSame("APP_KEY=example\n", file_get_contents($full.'/.env'));
    }

    // --- Reference: "POST /apps does not overwrite existing .env" ---

    public function test_does_not_overwrite_an_existing_env_file(): void
    {
        $full = $this->reposPath.'/env-keep';
        mkdir($full.'/.git', 0755, true);
        file_put_contents($full.'/.env.example', "APP_KEY=example\n");
        file_put_contents($full.'/.env', "APP_KEY=real\n");

        $this->createApp([
            'name' => 'EnvKeep',
            'repo_url' => 'https://github.com/x/y.git',
            'branch' => 'main',
            'path' => 'env-keep',
            'skip_clone' => true,
        ])->assertHasNoFormErrors();

        $this->assertSame("APP_KEY=real\n", file_get_contents($full.'/.env'));
    }

    // --- A clone failure is a form error, not a stack trace ---

    public function test_a_clone_failure_surfaces_as_a_validation_error_on_repo_url(): void
    {
        $this->assertFormError($this->createApp(
            [
                'name' => 'Broken',
                'repo_url' => 'https://github.com/x/nope.git',
                'branch' => 'main',
                'path' => 'broken',
            ],
            cloneError: 'Repository not found.',
        ), 'repo_url', 'Clone failed: Repository not found.');

        // No half-created app: the row must not exist when the working copy
        // does not.
        $this->assertNull(App::query()->where('name', 'Broken')->first());
    }
}
