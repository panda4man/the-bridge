<?php

namespace Tests\Feature\Api;

use App\Enums\DeploymentStatus;
use App\Jobs\DeployApp;
use App\Models\App;
use App\Models\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Ported from reference/tests/Feature/apiDeploy.test.ts, extended per the
 * phase brief: the reference file only exercises the auth ladder against
 * /api/apps and /api/apps/:id/deploy; this port pins the same ladder on
 * /api/deployments/:id and /api/deployments/:id/log too, since all four
 * routes share the same api.token middleware and the brief asks for "every
 * auth outcome on every guarded route."
 *
 * QUEUE_CONNECTION=sync in phpunit.xml, so DeployApp::dispatch() (inside
 * DeployAction::queue()) would run a full deploy in-process without
 * Bus::fake() — every test here fakes the bus, mirroring
 * tests/Feature/WebhooksTest.php.
 */
class ApiDeployTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-bridge-token';

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        config(['bridge.api_token' => self::TOKEN]);
    }

    private function makeApp(array $overrides = []): App
    {
        return App::factory()->create(array_merge([
            'name' => 'ApiApp',
            'repo_url' => 'https://github.com/x/y.git',
            'branch' => 'main',
        ], $overrides));
    }

    private function makeDeployment(App $app, array $overrides = []): Deployment
    {
        return Deployment::factory()->for($app)->create($overrides);
    }

    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    // ==================================================================
    // Auth ladder — one block per guarded route. 503 (unconfigured),
    // 401 (missing header), 401 (bad token), then a success case.
    // ==================================================================

    // --- GET /api/apps ---

    public function test_apps_index_returns_503_when_no_token_is_configured(): void
    {
        config(['bridge.api_token' => '']);

        $response = $this->getJson('/api/apps', $this->auth('anything'));

        $response->assertStatus(503);
        $response->assertExactJson(['error' => 'API token not configured']);
    }

    public function test_apps_index_returns_401_when_authorization_header_is_missing(): void
    {
        $response = $this->getJson('/api/apps');

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Missing or malformed Authorization header']);
    }

    public function test_apps_index_returns_401_when_token_is_wrong(): void
    {
        $response = $this->getJson('/api/apps', $this->auth('nope'));

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Invalid token']);
    }

    public function test_apps_index_returns_200_with_exactly_five_keys_per_app_as_a_bare_array(): void
    {
        $app = $this->makeApp(['name' => 'ApiApp', 'branch' => 'main']);

        $response = $this->getJson('/api/apps', $this->auth(self::TOKEN));

        $response->assertOk();
        $body = $response->json();

        $this->assertIsArray($body);
        $this->assertArrayNotHasKey('data', $body); // not a wrapped API Resource collection

        $row = collect($body)->firstWhere('id', $app->id);
        $this->assertNotNull($row);
        $this->assertSame(['id', 'name', 'branch', 'status', 'repo_url'], array_keys($row));
        $this->assertSame('ApiApp', $row['name']);
        $this->assertSame('main', $row['branch']);
        $this->assertSame('idle', $row['status']);
        $this->assertSame($app->repo_url, $row['repo_url']);
    }

    // --- POST /api/apps/{id}/deploy ---

    public function test_deploy_returns_503_when_no_token_is_configured(): void
    {
        config(['bridge.api_token' => '']);
        $app = $this->makeApp();

        $response = $this->postJson("/api/apps/{$app->id}/deploy", [], $this->auth('anything'));

        $response->assertStatus(503);
        $response->assertExactJson(['error' => 'API token not configured']);
        Bus::assertNotDispatched(DeployApp::class);
    }

    public function test_deploy_returns_401_when_authorization_header_is_missing(): void
    {
        $app = $this->makeApp();

        $response = $this->postJson("/api/apps/{$app->id}/deploy");

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Missing or malformed Authorization header']);
        Bus::assertNotDispatched(DeployApp::class);
    }

    public function test_deploy_returns_401_when_token_is_wrong(): void
    {
        $app = $this->makeApp();

        $response = $this->postJson("/api/apps/{$app->id}/deploy", [], $this->auth('nope'));

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Invalid token']);
        Bus::assertNotDispatched(DeployApp::class);
    }

    public function test_deploy_returns_202_with_deployment_queued_body_and_dispatches_the_job(): void
    {
        $app = $this->makeApp();

        $response = $this->postJson("/api/apps/{$app->id}/deploy", [], $this->auth(self::TOKEN));

        $response->assertStatus(202);

        $deployment = Deployment::query()->where('app_id', $app->id)->sole();
        $response->assertExactJson([
            'deployment_id' => $deployment->id,
            'app_id' => $app->id,
            'status' => 'pending',
        ]);

        $this->assertSame(DeploymentStatus::Pending, $deployment->status);

        // A plain deploy must not carry a rollback_sha. DeployAction::queue()
        // takes one as an optional second argument, so passing anything here
        // silently turns every API deploy into a rollback to that commit.
        // Verified by mutation: queue($app, 'deadbeef') survived the whole
        // suite (QC round 2, K3-api-deploy-injects-rollback-sha) until this
        // assertion existed. ParityRoutesTest's deploy case already had it.
        $this->assertNull($deployment->rollback_sha);

        Bus::assertDispatched(DeployApp::class, fn (DeployApp $job): bool => $job->deploymentId === $deployment->id);
    }

    public function test_deploy_returns_404_for_unknown_app_and_does_not_dispatch(): void
    {
        $response = $this->postJson('/api/apps/999999/deploy', [], $this->auth(self::TOKEN));

        $response->assertStatus(404);
        $response->assertExactJson(['error' => 'Not found']);
        Bus::assertNotDispatched(DeployApp::class);
    }

    public function test_deploy_with_a_non_numeric_id_returns_404_not_500(): void
    {
        // A route parameter typed `int $id` would TypeError (500) on a
        // non-numeric segment; the reference's Number(req.params.id) ->
        // NaN -> not-found is ported via a string $id instead.
        $response = $this->postJson('/api/apps/not-an-id/deploy', [], $this->auth(self::TOKEN));

        $response->assertStatus(404);
        $response->assertExactJson(['error' => 'Not found']);
        Bus::assertNotDispatched(DeployApp::class);
    }

    // --- GET /api/deployments/{id} ---

    public function test_deployment_show_returns_503_when_no_token_is_configured(): void
    {
        config(['bridge.api_token' => '']);
        $app = $this->makeApp();
        $dep = $this->makeDeployment($app);

        $response = $this->getJson("/api/deployments/{$dep->id}", $this->auth('anything'));

        $response->assertStatus(503);
        $response->assertExactJson(['error' => 'API token not configured']);
    }

    public function test_deployment_show_returns_401_when_authorization_header_is_missing(): void
    {
        $app = $this->makeApp();
        $dep = $this->makeDeployment($app);

        $response = $this->getJson("/api/deployments/{$dep->id}");

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Missing or malformed Authorization header']);
    }

    public function test_deployment_show_returns_401_when_token_is_wrong(): void
    {
        $app = $this->makeApp();
        $dep = $this->makeDeployment($app);

        $response = $this->getJson("/api/deployments/{$dep->id}", $this->auth('nope'));

        $response->assertStatus(401);
        $response->assertExactJson(['error' => 'Invalid token']);
    }

    public function test_deployment_show_returns_status_json_with_app_name_and_log_length(): void
    {
        $app = $this->makeApp(['name' => 'ApiApp']);
        $dep = $this->makeDeployment($app, ['status' => DeploymentStatus::Running, 'commit_sha' => null, 'commit_message' => null]);
        $dep->appendLog('hello world');

        $response = $this->getJson("/api/deployments/{$dep->id}", $this->auth(self::TOKEN));

        $response->assertOk();
        $response->assertExactJson([
            'id' => $dep->id,
            'app_id' => $app->id,
            'app_name' => 'ApiApp',
            'status' => 'running',
            'commit_sha' => null,
            'commit_message' => null,
            'started_at' => null,
            'finished_at' => null,
            'log_length' => strlen('hello world'),
        ]);
    }

    public function test_deployment_show_log_length_is_zero_when_log_is_null(): void
    {
        $app = $this->makeApp();
        $dep = $this->makeDeployment($app, ['status' => DeploymentStatus::Pending]);

        $response = $this->getJson("/api/deployments/{$dep->id}", $this->auth(self::TOKEN));

        $response->assertOk();
        $response->assertJsonPath('log_length', 0);
    }

    public function test_deployment_show_returns_404_for_unknown_deployment(): void
    {
        $response = $this->getJson('/api/deployments/999999', $this->auth(self::TOKEN));

        $response->assertStatus(404);
        $response->assertExactJson(['error' => 'Not found']);
    }

    public function test_deployment_show_with_a_non_numeric_id_returns_404_not_500(): void
    {
        $response = $this->getJson('/api/deployments/not-an-id', $this->auth(self::TOKEN));

        $response->assertStatus(404);
        $response->assertExactJson(['error' => 'Not found']);
    }

    // --- GET /api/deployments/{id}/log ---

    public function test_deployment_log_returns_503_when_no_token_is_configured(): void
    {
        config(['bridge.api_token' => '']);
        $app = $this->makeApp();
        $dep = $this->makeDeployment($app);

        $response = $this->getJson("/api/deployments/{$dep->id}/log", $this->auth('anything'));

        $response->assertStatus(503);
        $response->assertExactJson(['error' => 'API token not configured']);
    }

    public function test_deployment_log_returns_401_when_authorization_header_is_missing(): void
    {
        $app = $this->makeApp();
        $dep = $this->makeDeployment($app);

        $response = $this->get("/api/deployments/{$dep->id}/log");

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Missing or malformed Authorization header']);
    }

    public function test_deployment_log_returns_401_when_token_is_wrong(): void
    {
        $app = $this->makeApp();
        $dep = $this->makeDeployment($app);

        $response = $this->get("/api/deployments/{$dep->id}/log", $this->auth('nope'));

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid token']);
    }

    public function test_deployment_log_returns_full_log_with_the_header_trio_when_offset_is_omitted(): void
    {
        $app = $this->makeApp();
        $dep = $this->makeDeployment($app, ['status' => DeploymentStatus::Success]);
        $dep->appendLog("line one\nline two\n");

        $response = $this->get("/api/deployments/{$dep->id}/log", $this->auth(self::TOKEN));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->assertSame("line one\nline two\n", $response->getContent());
        $this->assertSame((string) strlen("line one\nline two\n"), $response->headers->get('X-Log-Offset'));
        $this->assertSame('success', $response->headers->get('X-Deploy-Status'));
        $this->assertSame('true', $response->headers->get('X-Deploy-Done'));
    }

    public function test_deployment_log_slices_from_a_nonzero_offset_but_x_log_offset_stays_the_full_length(): void
    {
        $app = $this->makeApp();
        $dep = $this->makeDeployment($app, ['status' => DeploymentStatus::Success]);
        $full = "line one\nline two\n";
        $dep->appendLog($full);

        $response = $this->get("/api/deployments/{$dep->id}/log?offset=9", $this->auth(self::TOKEN));

        $response->assertOk();
        $this->assertSame('line two'."\n", $response->getContent());
        // The full length, not the slice length — this is what the caller
        // passes back as the next offset.
        $this->assertSame((string) strlen($full), $response->headers->get('X-Log-Offset'));
    }

    public function test_deployment_log_negative_offset_clamps_to_zero(): void
    {
        $app = $this->makeApp();
        $dep = $this->makeDeployment($app, ['status' => DeploymentStatus::Running]);
        $dep->appendLog('abcdef');

        $response = $this->get("/api/deployments/{$dep->id}/log?offset=-5", $this->auth(self::TOKEN));

        $response->assertOk();
        $this->assertSame('abcdef', $response->getContent());
    }

    public function test_deployment_log_non_numeric_offset_clamps_to_zero(): void
    {
        $app = $this->makeApp();
        $dep = $this->makeDeployment($app, ['status' => DeploymentStatus::Running]);
        $dep->appendLog('abcdef');

        $response = $this->get("/api/deployments/{$dep->id}/log?offset=notanumber", $this->auth(self::TOKEN));

        $response->assertOk();
        $this->assertSame('abcdef', $response->getContent());
    }

    public function test_deployment_log_deploy_done_is_false_while_pending_or_running(): void
    {
        $app = $this->makeApp();

        $pending = $this->makeDeployment($app, ['status' => DeploymentStatus::Pending]);
        $running = $this->makeDeployment($app, ['status' => DeploymentStatus::Running]);

        $this->assertSame('false', $this->get("/api/deployments/{$pending->id}/log", $this->auth(self::TOKEN))->headers->get('X-Deploy-Done'));
        $this->assertSame('false', $this->get("/api/deployments/{$running->id}/log", $this->auth(self::TOKEN))->headers->get('X-Deploy-Done'));
    }

    public function test_deployment_log_deploy_done_is_true_for_success_and_failed(): void
    {
        $app = $this->makeApp();

        $success = $this->makeDeployment($app, ['status' => DeploymentStatus::Success]);
        $failed = $this->makeDeployment($app, ['status' => DeploymentStatus::Failed]);

        $this->assertSame('true', $this->get("/api/deployments/{$success->id}/log", $this->auth(self::TOKEN))->headers->get('X-Deploy-Done'));
        $this->assertSame('true', $this->get("/api/deployments/{$failed->id}/log", $this->auth(self::TOKEN))->headers->get('X-Deploy-Done'));
    }

    public function test_deployment_log_returns_404_for_unknown_deployment(): void
    {
        $response = $this->get('/api/deployments/999999/log', $this->auth(self::TOKEN));

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Not found']);
    }

    public function test_deployment_log_with_a_non_numeric_id_returns_404_not_500(): void
    {
        $response = $this->get('/api/deployments/not-an-id/log', $this->auth(self::TOKEN));

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Not found']);
    }

    public function test_deployment_log_of_a_deployment_with_no_log_yet_returns_empty_body(): void
    {
        $app = $this->makeApp();
        $dep = $this->makeDeployment($app, ['status' => DeploymentStatus::Pending, 'log' => null]);

        $response = $this->get("/api/deployments/{$dep->id}/log", $this->auth(self::TOKEN));

        $response->assertOk();
        $this->assertSame('', $response->getContent());
        $this->assertSame('0', $response->headers->get('X-Log-Offset'));
    }
}
