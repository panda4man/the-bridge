<?php

namespace Tests\Feature;

use App\Jobs\DeployApp;
use App\Models\App;
use App\Models\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ports reference/tests/Feature/webhooks.test.ts (POST /apps/:id/webhook),
 * plus the response-ladder cases the reference file didn't spell out as
 * separate tests (missing signature, non-JSON body) — see
 * reference/src/routes/apps.ts:212-239 for the ladder itself.
 *
 * The route lives outside the `web` group (see routes/webhook.php), so
 * these requests are unauthenticated and carry no CSRF token — matching
 * how GitHub actually calls it.
 */
class WebhooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    private function makeApp(array $overrides = []): App
    {
        return App::factory()->create(array_merge([
            'name' => 'WH',
            'repo_url' => 'https://github.com/x/y.git',
            'branch' => 'main',
        ], $overrides));
    }

    private function sign(string $secret, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    private function postWebhook(int|string $id, string $body, array $headers = []): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $this->call('POST', "/apps/{$id}/webhook", [], [], [], $server, $body);
    }

    /**
     * Every other case in this file uses an app tracking `main`, so nothing
     * distinguishes "compares against the app's branch" from "compares
     * against the literal string 'main'". This app tracks `develop`: the push
     * to `develop` must deploy and the push to `main` must skip — the exact
     * opposite of what a hardcoded 'main' would do.
     *
     * Verified by mutation: replacing `$pushedBranch !== $app->branch` with
     * `$pushedBranch !== 'main'` left the entire suite green (QC round 2,
     * mutation V1-webhook-hardcoded-branch) and is killed only by this test.
     * Without it, every app not tracking `main` would ignore its own pushes
     * and deploy on someone else's.
     */
    public function test_branch_comparison_uses_the_apps_own_branch_not_a_hardcoded_main(): void
    {
        $secret = bin2hex(random_bytes(32));
        $app = $this->makeApp(['webhook_secret' => $secret, 'branch' => 'develop']);

        $body = json_encode(['ref' => 'refs/heads/develop']);
        $response = $this->postWebhook($app->id, $body, [
            'X-Hub-Signature-256' => $this->sign($secret, $body),
        ]);

        $response->assertStatus(204);
        $this->assertSame(1, Deployment::query()->count());
        Bus::assertDispatched(DeployApp::class);

        $otherBody = json_encode(['ref' => 'refs/heads/main']);
        $otherResponse = $this->postWebhook($app->id, $otherBody, [
            'X-Hub-Signature-256' => $this->sign($secret, $otherBody),
        ]);

        $otherResponse->assertStatus(200);
        $otherResponse->assertJson([
            'skipped' => true,
            'reason' => 'Push was to main, app tracks develop',
        ]);
        $this->assertSame(1, Deployment::query()->count());
    }

    // --- Reference: "POST /apps/:id/webhook with valid signature queues deploy and returns 204" ---

    public function test_valid_signature_queues_deploy_and_returns_204(): void
    {
        $secret = bin2hex(random_bytes(32));
        $app = $this->makeApp(['webhook_secret' => $secret]);

        $body = json_encode(['ref' => 'refs/heads/main']);
        $sig = $this->sign($secret, $body);

        $response = $this->postWebhook($app->id, $body, ['X-Hub-Signature-256' => $sig]);

        $response->assertStatus(204);
        $response->assertNoContent();

        $deployment = Deployment::query()->sole();
        $this->assertSame($app->id, $deployment->app_id);

        Bus::assertDispatched(DeployApp::class, fn (DeployApp $job): bool => $job->deploymentId === $deployment->id);
    }

    // --- Reference: "POST /apps/:id/webhook with invalid signature returns 401" ---

    public function test_invalid_signature_returns_401_and_does_not_deploy(): void
    {
        $secret = bin2hex(random_bytes(32));
        $app = $this->makeApp(['webhook_secret' => $secret]);

        $body = json_encode(['ref' => 'refs/heads/main']);

        $response = $this->postWebhook($app->id, $body, ['X-Hub-Signature-256' => 'sha256=badsignature']);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid signature']);

        $this->assertSame(0, Deployment::query()->count());
        Bus::assertNotDispatched(DeployApp::class);
    }

    // --- Reference: "POST /apps/:id/webhook with branch mismatch returns 200 no-op" ---

    public function test_branch_mismatch_returns_200_skipped_and_does_not_deploy(): void
    {
        $secret = bin2hex(random_bytes(32));
        $app = $this->makeApp(['webhook_secret' => $secret, 'branch' => 'main']);

        $body = json_encode(['ref' => 'refs/heads/other-branch']);
        $sig = $this->sign($secret, $body);

        $response = $this->postWebhook($app->id, $body, ['X-Hub-Signature-256' => $sig]);

        $response->assertStatus(200);
        $response->assertJson(['skipped' => true, 'reason' => 'Push was to other-branch, app tracks main']);

        $this->assertSame(0, Deployment::query()->count());
        Bus::assertNotDispatched(DeployApp::class);
    }

    // --- Reference: "POST /apps/:id/webhook returns 400 when no secret configured" ---

    public function test_no_webhook_secret_configured_returns_400_and_does_not_deploy(): void
    {
        $app = $this->makeApp(['webhook_secret' => null]);

        $response = $this->postWebhook($app->id, json_encode(['ref' => 'refs/heads/main']));

        $response->assertStatus(400);
        $response->assertJson(['error' => 'No webhook secret configured.']);

        Bus::assertNotDispatched(DeployApp::class);
    }

    // --- Not in the reference as a standalone case, but part of the ladder ---

    public function test_unknown_app_returns_404_and_does_not_deploy(): void
    {
        $response = $this->postWebhook(999999, json_encode(['ref' => 'refs/heads/main']));

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Not found']);

        Bus::assertNotDispatched(DeployApp::class);
    }

    public function test_missing_signature_header_returns_401_and_does_not_deploy(): void
    {
        $secret = bin2hex(random_bytes(32));
        $app = $this->makeApp(['webhook_secret' => $secret]);

        $response = $this->postWebhook($app->id, json_encode(['ref' => 'refs/heads/main']));

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Missing signature']);

        Bus::assertNotDispatched(DeployApp::class);
    }

    // --- Reference behaviour: a non-JSON body with a *valid* signature still
    // deploys, because the reference catches the JSON.parse failure and
    // falls back to an empty payload (no `ref`, so nothing to compare). ---

    public function test_non_json_body_with_valid_signature_deploys_anyway(): void
    {
        $secret = bin2hex(random_bytes(32));
        $app = $this->makeApp(['webhook_secret' => $secret]);

        $body = 'not json at all';
        $sig = $this->sign($secret, $body);

        $response = $this->postWebhook($app->id, $body, ['X-Hub-Signature-256' => $sig]);

        $response->assertStatus(204);

        $deployment = Deployment::query()->sole();
        Bus::assertDispatched(DeployApp::class, fn (DeployApp $job): bool => $job->deploymentId === $deployment->id);
    }

    public function test_valid_json_with_no_ref_key_deploys(): void
    {
        $secret = bin2hex(random_bytes(32));
        $app = $this->makeApp(['webhook_secret' => $secret]);

        $body = json_encode(['zen' => 'Keep it logically awesome.']);
        $sig = $this->sign($secret, $body);

        $response = $this->postWebhook($app->id, $body, ['X-Hub-Signature-256' => $sig]);

        $response->assertStatus(204);
        Bus::assertDispatched(DeployApp::class);
    }

    public function test_matching_branch_deploys_instead_of_skipping(): void
    {
        $secret = bin2hex(random_bytes(32));
        $app = $this->makeApp(['webhook_secret' => $secret, 'branch' => 'main']);

        $body = json_encode(['ref' => 'refs/heads/main']);
        $sig = $this->sign($secret, $body);

        $response = $this->postWebhook($app->id, $body, ['X-Hub-Signature-256' => $sig]);

        $response->assertStatus(204);
        Bus::assertDispatched(DeployApp::class);
    }

    public function test_webhook_route_is_outside_the_web_group_and_accepts_requests_without_a_csrf_token(): void
    {
        // No session, no _token — a request registered inside `web` would
        // 419 here. This is the regression that motivated routes/webhook.php.
        $secret = bin2hex(random_bytes(32));
        $app = $this->makeApp(['webhook_secret' => $secret]);

        $body = json_encode(['ref' => 'refs/heads/main']);
        $sig = $this->sign($secret, $body);

        $response = $this->postWebhook($app->id, $body, ['X-Hub-Signature-256' => $sig]);

        $response->assertStatus(204);
    }
}
