<?php

namespace Tests\Unit;

use App\Enums\DeploymentStatus;
use App\Models\App;
use App\Models\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ported from reference/tests/Unit/models.test.ts ("Deployment model"
 * describe block), case for case, plus the findLastSuccessful() and
 * ANSI-stripping coverage called out explicitly in the Phase 1 spec (the
 * reference has no dedicated test for those, but they are behavioural
 * contract worth locking down).
 */
class DeploymentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_inserts_a_deployment(): void
    {
        $app = App::create(['name' => 'App', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/app']);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $this->assertNotNull($dep->id);
        $this->assertSame(DeploymentStatus::Pending, $dep->status);
        $this->assertSame($app->id, $dep->app_id);
    }

    public function test_find_includes_app_data_via_relation(): void
    {
        $app = App::create(['name' => 'MyApp', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/myapp']);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $found = Deployment::with('app')->find($dep->id);

        $this->assertSame('MyApp', $found->app->name);
    }

    public function test_append_log_concatenates_chunks(): void
    {
        $app = App::create(['name' => 'App', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/app2']);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Running]);

        $dep->appendLog("line 1\n");
        $dep->appendLog("line 2\n");

        $this->assertSame("line 1\nline 2\n", $dep->fresh()->log);
    }

    public function test_append_log_strips_ansi_escapes_and_carriage_returns(): void
    {
        $app = App::create(['name' => 'App', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/app-ansi']);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Running]);

        $dep->appendLog("\x1b[32mgreen\x1b[0m\r\nplain\r\n");

        $this->assertSame("green\nplain\n", $dep->fresh()->log);
    }

    public function test_update_changes_status_and_timestamps(): void
    {
        $app = App::create(['name' => 'App', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/app3']);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $dep->update(['status' => DeploymentStatus::Success, 'finished_at' => now()]);

        $this->assertSame(DeploymentStatus::Success, $dep->fresh()->status);
    }

    public function test_list_for_app_returns_deployments_newest_first(): void
    {
        $app = App::create(['name' => 'App', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/app4']);
        Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);
        Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Running]);

        $deps = $app->deployments()->orderByDesc('id')->get();

        $this->assertCount(2, $deps);
        $this->assertSame(DeploymentStatus::Running, $deps->first()->status);
    }

    public function test_find_last_successful_requires_success_status(): void
    {
        $app = App::create(['name' => 'App', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/rollback1']);
        Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Failed, 'commit_sha' => 'aaa']);
        $latest = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Running, 'commit_sha' => 'bbb']);

        $this->assertNull(Deployment::findLastSuccessful($app->id, $latest->id));
    }

    public function test_find_last_successful_requires_commit_sha_not_null(): void
    {
        $app = App::create(['name' => 'App', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/rollback2']);
        Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Success, 'commit_sha' => null]);
        $latest = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Running, 'commit_sha' => 'bbb']);

        $this->assertNull(Deployment::findLastSuccessful($app->id, $latest->id));
    }

    public function test_find_last_successful_requires_id_before_given_id(): void
    {
        $app = App::create(['name' => 'App', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/rollback3']);
        $earlier = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Success, 'commit_sha' => 'aaa']);

        // A "later" successful deploy with a lower beforeId than itself must not match.
        $this->assertNull(Deployment::findLastSuccessful($app->id, $earlier->id));
    }

    public function test_find_last_successful_returns_the_most_recent_match(): void
    {
        $app = App::create(['name' => 'App', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/rollback4']);
        Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Success, 'commit_sha' => 'aaa']);
        $second = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Success, 'commit_sha' => 'bbb']);
        $current = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Running, 'commit_sha' => 'ccc']);

        $result = Deployment::findLastSuccessful($app->id, $current->id);

        $this->assertNotNull($result);
        $this->assertSame($second->id, $result->id);
        $this->assertSame('bbb', $result->commit_sha);
    }

    public function test_find_last_successful_is_scoped_to_the_owning_app(): void
    {
        $appA = App::create(['name' => 'App A', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/scope-a']);
        $appB = App::create(['name' => 'App B', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/scope-b']);

        // App B has an earlier successful, SHA-bearing deployment that
        // satisfies every filter except app_id.
        Deployment::create(['app_id' => $appB->id, 'status' => DeploymentStatus::Success, 'commit_sha' => 'b-success']);

        // App A's own current deployment — this is the beforeId.
        $currentForA = Deployment::create(['app_id' => $appA->id, 'status' => DeploymentStatus::Running, 'commit_sha' => 'a-current']);

        // Without the app_id filter this would wrongly return App B's
        // deployment: a rollback on app A could select app B's commit SHA.
        $this->assertNull(Deployment::findLastSuccessful($appA->id, $currentForA->id));
    }

    public function test_append_log_preserves_other_dirty_attributes(): void
    {
        $app = App::create(['name' => 'App', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/app-dirty']);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Running]);

        // Simulate Phase 3 holding one instance across a deploy: status and
        // commit_sha are assigned but not yet saved when appendLog() runs.
        $dep->status = DeploymentStatus::Success;
        $dep->commit_sha = 'deadbeef';

        $dep->appendLog("build output\n");
        $dep->save();

        $fresh = $dep->fresh();
        $this->assertSame(DeploymentStatus::Success, $fresh->status);
        $this->assertSame('deadbeef', $fresh->commit_sha);
        $this->assertSame("build output\n", $fresh->log);
    }

    /**
     * B3-residual: appendLog() must not leave `log` dirty on the instance.
     * If it did, a later save() would be a read-modify-write that writes
     * back this instance's stale in-memory copy of `log`, clobbering
     * anything a concurrent writer appended to the row in between — exactly
     * the hazard the SQL-level `COALESCE(log, '') || ?` concatenation in
     * appendLog() exists to avoid (reference/src/models/deployment.ts:64).
     * Phase 3 (a worker streaming build output) and Phase 5 (the API
     * updating the same row) are the concurrent writers this guards
     * against. No real concurrency needed to prove it: a second writer is
     * simulated with a raw UPDATE between appendLog() and save().
     */
    public function test_append_log_does_not_clobber_a_concurrent_writers_chunk_on_next_save(): void
    {
        $app = App::create(['name' => 'App', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/app-concurrent']);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Running]);

        $dep->status = DeploymentStatus::Success;
        $dep->appendLog("worker chunk\n");

        // Simulate a concurrent writer appending to the same row between
        // this instance's appendLog() and its own save().
        DB::update(
            "update deployments set log = COALESCE(log, '') || ? where id = ?",
            ["concurrent\n", $dep->id]
        );

        $dep->save();

        $this->assertStringContainsString('concurrent', $dep->fresh()->log);
    }

    public function test_deployments_are_deleted_when_owning_app_is_deleted(): void
    {
        $app = App::create(['name' => 'Cascade', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/cascade']);
        $dep = Deployment::create(['app_id' => $app->id, 'status' => DeploymentStatus::Pending]);

        $app->delete();

        $this->assertNull(Deployment::find($dep->id));
    }
}
