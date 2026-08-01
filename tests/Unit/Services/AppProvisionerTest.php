<?php

namespace Tests\Unit\Services;

use App\Exceptions\CloneFailed;
use App\Models\App;
use App\Services\AppProvisioner;
use App\Services\Process\ProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

/**
 * The filesystem/git side effects of an app's lifecycle, in isolation from
 * Filament. Every message asserted here is a parity contract with
 * reference/src/validators/appValidators.ts and reference/src/routes/apps.ts.
 */
class AppProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private string $reposPath;

    private FakeProcessRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reposPath = sys_get_temp_dir().'/bridge-prov-'.bin2hex(random_bytes(6));
        mkdir($this->reposPath, 0755, true);
        config(['bridge.repos_path' => $this->reposPath]);

        $this->runner = new FakeProcessRunner;
        $this->app->instance(ProcessRunner::class, $this->runner);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->reposPath);

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

    private function provisioner(): AppProvisioner
    {
        return app(AppProvisioner::class);
    }

    // --- fullPath() ---

    public function test_full_path_joins_the_relative_segment_onto_the_configured_repos_path(): void
    {
        $this->assertSame($this->reposPath.'/my-app', AppProvisioner::fullPath('my-app'));
    }

    public function test_full_path_does_not_double_the_separator_for_a_leading_slash_or_surrounding_space(): void
    {
        // config('bridge.repos_path') is already trailing-slash-normalised;
        // this is the other end of the join.
        $this->assertSame($this->reposPath.'/my-app', AppProvisioner::fullPath('/my-app'));
        $this->assertSame($this->reposPath.'/my-app', AppProvisioner::fullPath('  my-app  '));
    }

    // --- validateNewPath(): the rules, in the reference's own order ---

    public function test_a_path_already_used_by_another_app_is_rejected_whether_cloning_or_importing(): void
    {
        App::factory()->create(['path' => $this->reposPath.'/taken']);

        // First rule, and it applies to BOTH branches — an importing operator
        // must get this message, not "directory does not exist".
        $this->assertSame(
            'An app already uses this path.',
            $this->provisioner()->validateNewPath($this->reposPath.'/taken', skipClone: false),
        );
        $this->assertSame(
            'An app already uses this path.',
            $this->provisioner()->validateNewPath($this->reposPath.'/taken', skipClone: true),
        );
    }

    public function test_importing_requires_the_directory_to_exist(): void
    {
        $this->assertSame(
            'Directory does not exist at this path.',
            $this->provisioner()->validateNewPath($this->reposPath.'/nope', skipClone: true),
        );
    }

    public function test_importing_requires_the_directory_to_be_a_git_repository(): void
    {
        mkdir($this->reposPath.'/plain', 0755, true);

        $this->assertSame(
            'Directory exists but is not a git repository.',
            $this->provisioner()->validateNewPath($this->reposPath.'/plain', skipClone: true),
        );
    }

    public function test_importing_accepts_an_existing_git_checkout(): void
    {
        mkdir($this->reposPath.'/repo/.git', 0755, true);

        $this->assertNull(
            $this->provisioner()->validateNewPath($this->reposPath.'/repo', skipClone: true),
        );
    }

    public function test_cloning_requires_the_directory_to_be_absent(): void
    {
        mkdir($this->reposPath.'/occupied', 0755, true);

        // The exact opposite rule to the import branch, which is why the
        // messages must not be interchangeable.
        $this->assertSame(
            'Directory already exists on disk.',
            $this->provisioner()->validateNewPath($this->reposPath.'/occupied', skipClone: false),
        );
    }

    public function test_cloning_accepts_a_path_that_does_not_exist_yet(): void
    {
        $this->assertNull(
            $this->provisioner()->validateNewPath($this->reposPath.'/fresh', skipClone: false),
        );
    }

    public function test_cloning_rejects_a_path_occupied_by_a_plain_file(): void
    {
        touch($this->reposPath.'/afile');

        // is_dir() would say no here; the reference uses existsSync(), which
        // says yes. Cloning into an existing file fails at the git level, so
        // the friendly message is the right answer.
        $this->assertSame(
            'Directory already exists on disk.',
            $this->provisioner()->validateNewPath($this->reposPath.'/afile', skipClone: false),
        );
    }

    // --- provision() ---

    public function test_provision_clones_with_the_exact_git_argv(): void
    {
        $target = $this->reposPath.'/cloned';
        $this->runner->queueSuccess();

        $this->provisioner()->provision($target, 'git@github.com:x/y.git', 'develop', skipClone: false);

        $this->assertSame(
            ['git', 'clone', '--branch', 'develop', '-c', 'safe.directory=*', 'git@github.com:x/y.git', $target],
            $this->runner->commands()[0],
        );
    }

    public function test_provision_does_not_clone_when_importing(): void
    {
        mkdir($this->reposPath.'/imported/.git', 0755, true);

        $this->provisioner()->provision($this->reposPath.'/imported', 'r', 'main', skipClone: true);

        // FakeProcessRunner is strict about an exhausted queue, and nothing
        // was queued — so any process at all would have thrown.
        $this->assertSame([], $this->runner->commands());
    }

    public function test_a_failing_clone_throws_with_the_user_facing_prefix(): void
    {
        $this->runner->queueFailure(128, '', 'Repository not found.');

        $this->expectException(CloneFailed::class);
        $this->expectExceptionMessage('Clone failed: Repository not found.');

        $this->provisioner()->provision($this->reposPath.'/broken', 'r', 'main', skipClone: false);
    }

    // --- seedEnvFile() ---

    public function test_seeding_copies_env_example_when_env_is_absent(): void
    {
        $dir = $this->reposPath.'/seed';
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/.env.example', "APP_KEY=example\n");

        $this->provisioner()->seedEnvFile($dir);

        $this->assertSame("APP_KEY=example\n", file_get_contents($dir.'/.env'));
    }

    public function test_seeding_never_overwrites_an_existing_env(): void
    {
        $dir = $this->reposPath.'/keep';
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/.env.example', "APP_KEY=example\n");
        file_put_contents($dir.'/.env', "APP_KEY=real\n");

        $this->provisioner()->seedEnvFile($dir);

        $this->assertSame("APP_KEY=real\n", file_get_contents($dir.'/.env'));
    }

    public function test_seeding_does_nothing_without_an_env_example(): void
    {
        $dir = $this->reposPath.'/bare';
        mkdir($dir, 0755, true);

        $this->provisioner()->seedEnvFile($dir);

        $this->assertFileDoesNotExist($dir.'/.env');
    }

    // --- readEnvFile()/writeEnvFile() ---

    public function test_reading_a_missing_env_returns_an_empty_string_rather_than_erroring(): void
    {
        $dir = $this->reposPath.'/noenv';
        mkdir($dir, 0755, true);

        $this->assertSame('', $this->provisioner()->readEnvFile($dir));
    }

    public function test_writing_replaces_the_env_contents(): void
    {
        $dir = $this->reposPath.'/writeenv';
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/.env', "OLD=1\n");

        $this->provisioner()->writeEnvFile($dir, "NEW=1\n");

        $this->assertSame("NEW=1\n", file_get_contents($dir.'/.env'));
    }

    // --- destroy() ---

    public function test_destroy_brings_containers_down_with_the_exact_argv_and_a_wall_clock_timeout_then_removes_the_directory(): void
    {
        $dir = $this->reposPath.'/running';
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/docker-compose.yml', "services: {}\n");
        $this->runner->queueSuccess();

        $this->provisioner()->destroy($dir);

        $call = $this->runner->calls[0];
        $this->assertSame(['docker', 'compose', '-f', $dir.'/docker-compose.yml', 'down'], $call['command']);
        $this->assertSame($dir, $call['cwd']);
        // A WALL-CLOCK bound, not the idle timeout DeployApp uses: `docker
        // compose down` printing something every 59 seconds forever must
        // still be killed, unlike a legitimately long build.
        $this->assertSame(60.0, $call['timeout']);
        $this->assertNull($call['idleTimeout']);

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function test_destroy_skips_docker_entirely_when_there_is_no_compose_file(): void
    {
        $dir = $this->reposPath.'/nocompose';
        mkdir($dir, 0755, true);

        $this->provisioner()->destroy($dir);

        $this->assertSame([], $this->runner->commands());
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function test_destroy_still_removes_the_directory_when_docker_fails(): void
    {
        $dir = $this->reposPath.'/dockerdown';
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/docker-compose.yml', "services: {}\n");
        $this->runner->queueThrowable(new \RuntimeException('docker: command not found'));

        $this->provisioner()->destroy($dir);

        // Losing the DB row while the checkout stays on disk is the worse
        // failure, so nothing in destroy() is allowed to throw.
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function test_destroy_is_a_no_op_for_a_path_that_does_not_exist(): void
    {
        $this->provisioner()->destroy($this->reposPath.'/never-existed');

        $this->assertSame([], $this->runner->commands());
    }

    public function test_destroy_removes_a_directory_tree_recursively(): void
    {
        $dir = $this->reposPath.'/deep';
        mkdir($dir.'/a/b/c', 0755, true);
        file_put_contents($dir.'/a/b/c/file.txt', 'x');

        $this->provisioner()->destroy($dir);

        $this->assertDirectoryDoesNotExist($dir);
    }
}
