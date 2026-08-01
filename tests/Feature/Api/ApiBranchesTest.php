<?php

namespace Tests\Feature\Api;

use App\Services\Process\ProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

/**
 * Ported from reference/src/routes/api.ts:14-27 (GET /branches). There is
 * no dedicated reference test file for this route (it isn't exercised by
 * apiDeploy.test.ts or apiOpenapi.test.ts), so this mirrors the handler's
 * own branches directly: empty repo_url, sort + main/master hoisting, and
 * the always-200 failure shape.
 *
 * Public route — no api.token, no Authorization header sent anywhere here.
 */
class ApiBranchesTest extends TestCase
{
    use RefreshDatabase;

    private FakeProcessRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new FakeProcessRunner;
        $this->app->instance(ProcessRunner::class, $this->runner);
    }

    public function test_empty_repo_url_returns_empty_branches_without_shelling_out(): void
    {
        $response = $this->getJson('/api/branches');

        $response->assertOk();
        $response->assertExactJson(['branches' => []]);
        $this->assertSame([], $this->runner->calls);
    }

    public function test_missing_repo_url_query_param_returns_empty_branches_without_shelling_out(): void
    {
        $response = $this->getJson('/api/branches?repo_url=');

        $response->assertOk();
        $response->assertExactJson(['branches' => []]);
        $this->assertSame([], $this->runner->calls);
    }

    public function test_whitespace_only_repo_url_returns_empty_branches_without_shelling_out(): void
    {
        $response = $this->getJson('/api/branches?repo_url='.urlencode('   '));

        $response->assertOk();
        $response->assertExactJson(['branches' => []]);
        $this->assertSame([], $this->runner->calls);
    }

    public function test_returns_sorted_branches_with_main_and_master_hoisted_to_the_front(): void
    {
        $this->runner->queueSuccess(
            "abc\trefs/heads/zeta\n".
            "def\trefs/heads/master\n".
            "ghi\trefs/heads/alpha\n".
            "jkl\trefs/heads/main\n"
        );

        $response = $this->getJson('/api/branches?repo_url=https://example.com/x.git');

        $response->assertOk();
        $response->assertExactJson(['branches' => ['main', 'master', 'alpha', 'zeta']]);
    }

    public function test_hoists_only_master_when_main_is_absent(): void
    {
        $this->runner->queueSuccess(
            "abc\trefs/heads/develop\n".
            "def\trefs/heads/master\n"
        );

        $response = $this->getJson('/api/branches?repo_url=https://example.com/x.git');

        $response->assertOk();
        $response->assertExactJson(['branches' => ['master', 'develop']]);
    }

    public function test_returns_200_with_error_key_when_ls_remote_fails(): void
    {
        $this->runner->queueFailure(128, '', 'fatal: repository not found');

        $response = $this->getJson('/api/branches?repo_url=https://example.com/missing.git');

        $response->assertOk();
        $response->assertExactJson(['branches' => [], 'error' => 'Failed to fetch branches']);
    }

    public function test_ls_remote_uses_the_exact_verbatim_command(): void
    {
        $this->runner->queueSuccess('');

        $this->getJson('/api/branches?repo_url=https://example.com/x.git')->assertOk();

        $this->assertSame(
            ['git', 'ls-remote', '--heads', 'https://example.com/x.git'],
            $this->runner->calls[0]['command']
        );
    }
}
