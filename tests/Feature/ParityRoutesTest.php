<?php

namespace Tests\Feature;

use App\Enums\DeploymentStatus;
use App\Jobs\DeployApp;
use App\Models\App;
use App\Models\Deployment;
use App\Services\Process\ProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

/**
 * The reference's four un-prefixed `/apps/:id/...` endpoints, ported at
 * their exact verbatim paths — GET/POST /apps/{id}/env,
 * GET /apps/{id}/containers, POST /apps/{id}/deploy,
 * POST /apps/{id}/rollback. See App\Http\Controllers\ParityController and
 * routes/parity.php.
 *
 * Mirrors reference/tests/Feature/envEditor.test.ts,
 * containerStatus.test.ts, and rollback.test.ts, extended the same way
 * tests/Feature/Api/ApiDeployTest.php extended apiDeploy.test.ts: every
 * route gets the full auth ladder (503 unconfigured, 401 missing, 401 bad,
 * success), since all five carry api.token and the reference itself never
 * had auth to test.
 *
 * QUEUE_CONNECTION=sync in phpunit.xml, so DeployApp::dispatch() (inside
 * DeployAction::queue()) would run a full deploy in-process without
 * Bus::fake() — every test fakes the bus, mirroring WebhooksTest and
 * ApiDeployTest.
 *
 * The filesystem is REAL, not faked, for the env endpoints — a real temp
 * dir per test plus each app's `path` pointed at a subdirectory of it,
 * cleaned in tearDown(). This is the AppProvisionerTest/CreateAppTest
 * convention; it is also exactly the case that convention exists for, since
 * the parity-critical assertion here is "the file on disk is byte-identical
 * after a rejected write," which a faked filesystem couldn't prove.
 */
class ParityRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-bridge-token';

    private string $reposPath;

    private FakeProcessRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        config(['bridge.api_token' => self::TOKEN]);

        $this->reposPath = sys_get_temp_dir().'/bridge-parity-'.bin2hex(random_bytes(6));
        mkdir($this->reposPath, 0755, true);

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
            // A permission-denied test below chmod(0000)s a file; restore
            // write/read/execute before recursing so tearDown can actually
            // remove it instead of leaking the temp dir.
            @chmod($path, 0755);
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function makeApp(array $overrides = []): App
    {
        $path = $this->reposPath.'/app-'.bin2hex(random_bytes(4));
        mkdir($path, 0755, true);

        return App::factory()->create(array_merge([
            'name' => 'ParityApp',
            'repo_url' => 'https://github.com/x/y.git',
            'branch' => 'main',
            'path' => $path,
        ], $overrides));
    }

    // ==================================================================
    // GET /apps/{id}/env
    // ==================================================================

    public function test_env_get_returns_503_when_no_token_is_configured(): void
    {
        config(['bridge.api_token' => '']);
        $app = $this->makeApp();

        $response = $this->getJson("/apps/{$app->id}/env", $this->auth('anything'));

        $response->assertStatus(503);
        $response->assertExactJson(['error' => 'API token not configured']);
    }

    public function test_env_get_returns_401_when_authorization_header_is_missing(): void
    {
        $app = $this->makeApp();

        $response = $this->getJson("/apps/{$app->id}/env");

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Missing or malformed Authorization header']);
    }

    public function test_env_get_returns_401_when_token_is_wrong(): void
    {
        $app = $this->makeApp();

        $response = $this->getJson("/apps/{$app->id}/env", $this->auth('nope'));

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Invalid token']);
    }

    public function test_env_get_returns_the_files_content(): void
    {
        $app = $this->makeApp();
        file_put_contents($app->path.'/.env', "FOO=bar\n");

        $response = $this->getJson("/apps/{$app->id}/env", $this->auth(self::TOKEN));

        $response->assertOk();
        $response->assertExactJson(['content' => "FOO=bar\n"]);
    }

    public function test_env_get_returns_empty_string_when_the_file_is_missing_not_a_404(): void
    {
        $app = $this->makeApp();

        $response = $this->getJson("/apps/{$app->id}/env", $this->auth(self::TOKEN));

        $response->assertOk();
        $response->assertExactJson(['content' => '']);
    }

    public function test_env_get_returns_404_for_unknown_app(): void
    {
        $response = $this->getJson('/apps/999999/env', $this->auth(self::TOKEN));

        $response->assertStatus(404);
        $response->assertExactJson(['error' => 'Not found']);
    }

    public function test_env_get_returns_500_when_the_file_cannot_be_read(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('chmod(0000) does not block reads while running as root.');
        }

        $app = $this->makeApp();
        $envPath = $app->path.'/.env';
        file_put_contents($envPath, "SECRET=1\n");
        chmod($envPath, 0000);

        $response = $this->getJson("/apps/{$app->id}/env", $this->auth(self::TOKEN));

        $response->assertStatus(500);
        $response->assertExactJson(['error' => 'Could not read .env file']);
    }

    // ==================================================================
    // POST /apps/{id}/env
    // ==================================================================

    public function test_env_post_returns_503_when_no_token_is_configured(): void
    {
        config(['bridge.api_token' => '']);
        $app = $this->makeApp();

        $response = $this->postJson("/apps/{$app->id}/env", ['content' => 'X=1'], $this->auth('anything'));

        $response->assertStatus(503);
        $response->assertExactJson(['error' => 'API token not configured']);
    }

    public function test_env_post_returns_401_when_authorization_header_is_missing(): void
    {
        $app = $this->makeApp();

        $response = $this->postJson("/apps/{$app->id}/env", ['content' => 'X=1']);

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Missing or malformed Authorization header']);
    }

    public function test_env_post_returns_401_when_token_is_wrong(): void
    {
        $app = $this->makeApp();

        $response = $this->postJson("/apps/{$app->id}/env", ['content' => 'X=1'], $this->auth('nope'));

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Invalid token']);
    }

    public function test_env_post_writes_non_empty_content_and_returns_ok(): void
    {
        $app = $this->makeApp();
        file_put_contents($app->path.'/.env', "OLD=1\n");

        $response = $this->postJson("/apps/{$app->id}/env", ['content' => "NEW=1\n"], $this->auth(self::TOKEN));

        $response->assertOk();
        $response->assertExactJson(['ok' => true]);
        $this->assertSame("NEW=1\n", file_get_contents($app->path.'/.env'));
    }

    public function test_env_post_returns_404_for_unknown_app(): void
    {
        $response = $this->postJson('/apps/999999/env', ['content' => 'X=1'], $this->auth(self::TOKEN));

        $response->assertStatus(404);
        $response->assertExactJson(['error' => 'Not found']);
    }

    // --- The parity-critical refusal: rejected writes must leave the file untouched. ---

    public function test_env_post_rejects_empty_string_and_leaves_the_existing_file_byte_identical(): void
    {
        $app = $this->makeApp();
        file_put_contents($app->path.'/.env', "EXISTING=1\n");

        $response = $this->postJson("/apps/{$app->id}/env", ['content' => ''], $this->auth(self::TOKEN));

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'Content must be a non-empty string']);
        $this->assertSame("EXISTING=1\n", file_get_contents($app->path.'/.env'));
    }

    public function test_env_post_rejects_whitespace_only_content_and_leaves_the_file_untouched(): void
    {
        $app = $this->makeApp();
        file_put_contents($app->path.'/.env', "EXISTING=1\n");

        $response = $this->postJson("/apps/{$app->id}/env", ['content' => "   \n\t  "], $this->auth(self::TOKEN));

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'Content must be a non-empty string']);
        $this->assertSame("EXISTING=1\n", file_get_contents($app->path.'/.env'));
    }

    public function test_env_post_rejects_non_string_content_and_leaves_the_file_untouched(): void
    {
        $app = $this->makeApp();
        file_put_contents($app->path.'/.env', "EXISTING=1\n");

        $response = $this->postJson("/apps/{$app->id}/env", ['content' => 123], $this->auth(self::TOKEN));

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'Content must be a non-empty string']);
        $this->assertSame("EXISTING=1\n", file_get_contents($app->path.'/.env'));
    }

    public function test_env_post_rejects_missing_content_key_and_leaves_the_file_untouched(): void
    {
        $app = $this->makeApp();
        file_put_contents($app->path.'/.env', "EXISTING=1\n");

        $response = $this->postJson("/apps/{$app->id}/env", [], $this->auth(self::TOKEN));

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'Content must be a non-empty string']);
        $this->assertSame("EXISTING=1\n", file_get_contents($app->path.'/.env'));
    }

    public function test_env_post_returns_500_when_the_file_cannot_be_written(): void
    {
        // A path whose directory was never created — writeEnvFile()'s
        // underlying file_put_contents() fails, which Laravel's error
        // handler converts into a catchable ErrorException.
        $app = $this->makeApp(['path' => $this->reposPath.'/never-created']);

        $response = $this->postJson("/apps/{$app->id}/env", ['content' => 'X=1'], $this->auth(self::TOKEN));

        $response->assertStatus(500);
        $response->assertExactJson(['error' => 'Could not write .env file']);
    }

    // ==================================================================
    // GET /apps/{id}/containers
    // ==================================================================

    public function test_containers_returns_503_when_no_token_is_configured(): void
    {
        config(['bridge.api_token' => '']);
        $app = $this->makeApp();

        $response = $this->getJson("/apps/{$app->id}/containers", $this->auth('anything'));

        $response->assertStatus(503);
        $response->assertExactJson(['error' => 'API token not configured']);
    }

    public function test_containers_returns_401_when_authorization_header_is_missing(): void
    {
        $app = $this->makeApp();

        $response = $this->getJson("/apps/{$app->id}/containers");

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Missing or malformed Authorization header']);
    }

    public function test_containers_returns_401_when_token_is_wrong(): void
    {
        $app = $this->makeApp();

        $response = $this->getJson("/apps/{$app->id}/containers", $this->auth('nope'));

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Invalid token']);
    }

    public function test_containers_returns_the_container_list(): void
    {
        $app = $this->makeApp();
        $this->runner->queueSuccess(
            '{"Name":"myapp-web-1","Service":"web","State":"running","Status":"Up 2 hours","Ports":"0.0.0.0:3000->3000/tcp"}'
        );

        $response = $this->getJson("/apps/{$app->id}/containers", $this->auth(self::TOKEN));

        $response->assertOk();
        $response->assertExactJson([
            'containers' => [
                ['name' => 'myapp-web-1', 'service' => 'web', 'state' => 'running', 'status' => 'Up 2 hours', 'ports' => '0.0.0.0:3000->3000/tcp'],
            ],
        ]);
        $this->assertSame($app->path, $this->runner->calls[0]['cwd']);
    }

    public function test_containers_returns_404_for_unknown_app(): void
    {
        $response = $this->getJson('/apps/999999/containers', $this->auth(self::TOKEN));

        $response->assertStatus(404);
        $response->assertExactJson(['error' => 'Not found']);
        $this->assertSame([], $this->runner->calls);
    }

    // ==================================================================
    // POST /apps/{id}/deploy
    // ==================================================================

    public function test_deploy_returns_503_when_no_token_is_configured(): void
    {
        config(['bridge.api_token' => '']);
        $app = $this->makeApp();

        $response = $this->postJson("/apps/{$app->id}/deploy", [], $this->auth('anything'));

        $response->assertStatus(503);
        $response->assertExactJson(['error' => 'API token not configured']);
        Bus::assertNotDispatched(DeployApp::class);
    }

    public function test_deploy_returns_401_when_authorization_header_is_missing(): void
    {
        $app = $this->makeApp();

        $response = $this->postJson("/apps/{$app->id}/deploy");

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Missing or malformed Authorization header']);
        Bus::assertNotDispatched(DeployApp::class);
    }

    public function test_deploy_returns_401_when_token_is_wrong(): void
    {
        $app = $this->makeApp();

        $response = $this->postJson("/apps/{$app->id}/deploy", [], $this->auth('nope'));

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Invalid token']);
        Bus::assertNotDispatched(DeployApp::class);
    }

    public function test_deploy_returns_202_with_the_queued_body_and_dispatches_the_job(): void
    {
        $app = $this->makeApp();

        $response = $this->postJson("/apps/{$app->id}/deploy", [], $this->auth(self::TOKEN));

        $response->assertStatus(202);

        $deployment = Deployment::query()->where('app_id', $app->id)->sole();
        $response->assertExactJson([
            'deployment_id' => $deployment->id,
            'app_id' => $app->id,
            'status' => 'pending',
        ]);
        $this->assertNull($deployment->rollback_sha);

        Bus::assertDispatched(DeployApp::class, fn (DeployApp $job): bool => $job->deploymentId === $deployment->id);
    }

    public function test_deploy_returns_404_for_unknown_app_and_does_not_dispatch(): void
    {
        $response = $this->postJson('/apps/999999/deploy', [], $this->auth(self::TOKEN));

        $response->assertStatus(404);
        $response->assertExactJson(['error' => 'Not found']);
        Bus::assertNotDispatched(DeployApp::class);
    }

    // ==================================================================
    // POST /apps/{id}/rollback
    // ==================================================================

    public function test_rollback_returns_503_when_no_token_is_configured(): void
    {
        config(['bridge.api_token' => '']);
        $app = $this->makeApp();
        $dep = Deployment::factory()->for($app)->create(['status' => DeploymentStatus::Success]);

        $response = $this->postJson("/apps/{$app->id}/rollback", ['deployment_id' => $dep->id], $this->auth('anything'));

        $response->assertStatus(503);
        $response->assertExactJson(['error' => 'API token not configured']);
        Bus::assertNotDispatched(DeployApp::class);
    }

    public function test_rollback_returns_401_when_authorization_header_is_missing(): void
    {
        $app = $this->makeApp();
        $dep = Deployment::factory()->for($app)->create(['status' => DeploymentStatus::Success]);

        $response = $this->postJson("/apps/{$app->id}/rollback", ['deployment_id' => $dep->id]);

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Missing or malformed Authorization header']);
        Bus::assertNotDispatched(DeployApp::class);
    }

    public function test_rollback_returns_401_when_token_is_wrong(): void
    {
        $app = $this->makeApp();
        $dep = Deployment::factory()->for($app)->create(['status' => DeploymentStatus::Success]);

        $response = $this->postJson("/apps/{$app->id}/rollback", ['deployment_id' => $dep->id], $this->auth('nope'));

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Invalid token']);
        Bus::assertNotDispatched(DeployApp::class);
    }

    public function test_rollback_returns_404_for_unknown_app_and_does_not_dispatch(): void
    {
        $response = $this->postJson('/apps/999999/rollback', ['deployment_id' => 1], $this->auth(self::TOKEN));

        $response->assertStatus(404);
        $response->assertExactJson(['error' => 'Not found']);
        Bus::assertNotDispatched(DeployApp::class);
    }

    public function test_rollback_returns_404_when_the_source_deployment_does_not_exist(): void
    {
        $app = $this->makeApp();

        $response = $this->postJson("/apps/{$app->id}/rollback", ['deployment_id' => 999999], $this->auth(self::TOKEN));

        $response->assertStatus(404);
        $response->assertExactJson(['error' => 'Not found']);
        Bus::assertNotDispatched(DeployApp::class);
    }

    /**
     * THE critical authorisation-boundary case: unlike the panel's
     * RollbackAction (which reads the app off the source deployment and
     * structurally cannot disagree with itself — see
     * docs/porting-notes.md's "Rollback: which of the reference's three
     * guards survived"), this endpoint takes both an app id and a
     * deployment id from the caller. Without this guard, a caller could
     * roll app A back to app B's commit.
     */
    public function test_rollback_returns_404_when_the_deployment_belongs_to_a_different_app(): void
    {
        $appA = $this->makeApp(['name' => 'AppA']);
        $appB = $this->makeApp(['name' => 'AppB']);
        $sourceOnB = Deployment::factory()->for($appB)->create([
            'status' => DeploymentStatus::Success,
            'commit_sha' => 'b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1',
        ]);

        $response = $this->postJson("/apps/{$appA->id}/rollback", ['deployment_id' => $sourceOnB->id], $this->auth(self::TOKEN));

        $response->assertStatus(404);
        $response->assertExactJson(['error' => 'Not found']);
        Bus::assertNotDispatched(DeployApp::class);
        $this->assertSame(1, Deployment::query()->count());
    }

    public function test_rollback_returns_400_when_the_source_deployment_has_no_commit_sha(): void
    {
        $app = $this->makeApp();
        $dep = Deployment::factory()->for($app)->create(['status' => DeploymentStatus::Success, 'commit_sha' => null]);

        $response = $this->postJson("/apps/{$app->id}/rollback", ['deployment_id' => $dep->id], $this->auth(self::TOKEN));

        $response->assertStatus(400);
        $response->assertExactJson(['error' => 'Deployment has no commit SHA — cannot rollback.']);
        Bus::assertNotDispatched(DeployApp::class);
    }

    public function test_rollback_creates_a_deployment_carrying_the_source_commit_sha_and_dispatches_it(): void
    {
        $app = $this->makeApp();
        $dep = Deployment::factory()->for($app)->create([
            'status' => DeploymentStatus::Success,
            'commit_sha' => 'abc123def456abc123def456abc123def456abc1',
        ]);

        $response = $this->postJson("/apps/{$app->id}/rollback", ['deployment_id' => $dep->id], $this->auth(self::TOKEN));

        $response->assertStatus(202);

        $rollback = Deployment::query()->where('id', '!=', $dep->id)->sole();
        $response->assertExactJson([
            'deployment_id' => $rollback->id,
            'app_id' => $app->id,
            'status' => 'pending',
        ]);
        $this->assertSame('abc123def456abc123def456abc123def456abc1', $rollback->rollback_sha);
        $this->assertSame($app->id, $rollback->app_id);
        // The source itself is untouched.
        $this->assertSame(DeploymentStatus::Success, $dep->fresh()->status);

        Bus::assertDispatched(DeployApp::class, fn (DeployApp $job): bool => $job->deploymentId === $rollback->id);
    }

    /**
     * Deviation from App\Filament\Actions\RollbackAction, deliberate: the
     * reference's route never restricted rollback to a SUCCESSFUL source
     * deployment — only its EJS view hid the button on non-successful rows.
     * Phase 4 kept that as a visibility-only rule to match; this endpoint
     * has no view to hide a button on, so it must not add the restriction
     * as a hard refusal either.
     */
    public function test_rollback_does_not_require_the_source_deployment_to_have_succeeded(): void
    {
        $app = $this->makeApp();
        $dep = Deployment::factory()->for($app)->create([
            'status' => DeploymentStatus::Failed,
            'commit_sha' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
        ]);

        $response = $this->postJson("/apps/{$app->id}/rollback", ['deployment_id' => $dep->id], $this->auth(self::TOKEN));

        $response->assertStatus(202);
        Bus::assertDispatched(DeployApp::class);
    }
}
