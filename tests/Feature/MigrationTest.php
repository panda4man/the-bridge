<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Deployment;
use App\Models\HealthCheck;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Ported from reference/tests/Feature/migration.test.ts. The reference
 * checked column presence on `apps`, `deployments` and its own `jobs` table.
 * The custom `jobs` table (queue/payload/attempts/reserved_at/available_at)
 * is dropped in this port in favour of Laravel's database queue (see
 * docs/porting-notes.md) — but Laravel's own skeleton-generated `jobs`
 * table (already present from Phase 0) happens to carry exactly the same
 * seven columns the reference asserted, so that case is ported as-is
 * rather than dropped. `health_checks` and `settings` column checks are
 * added on top, covering the rest of the Phase 1 schema.
 *
 * Also covers the two "get right" items from the Phase 1 spec: the real
 * unique index on apps.path, and that SQLite foreign key enforcement (and
 * therefore ON DELETE CASCADE) is actually active for this connection.
 */
class MigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_apps_table_has_correct_columns(): void
    {
        $cols = Schema::getColumnListing('apps');

        foreach ([
            'id', 'name', 'repo_url', 'branch', 'path', 'status',
            'health_url', 'health_check_interval', 'webhook_secret', 'deploy_steps',
            'created_at', 'updated_at',
        ] as $expected) {
            $this->assertContains($expected, $cols);
        }
    }

    public function test_deployments_table_has_correct_columns(): void
    {
        $cols = Schema::getColumnListing('deployments');

        foreach ([
            'id', 'app_id', 'status', 'log', 'started_at', 'finished_at',
            'commit_sha', 'commit_message', 'rollback_sha',
            'created_at', 'updated_at',
        ] as $expected) {
            $this->assertContains($expected, $cols);
        }
    }

    public function test_health_checks_table_has_correct_columns(): void
    {
        $cols = Schema::getColumnListing('health_checks');

        foreach (['id', 'app_id', 'status', 'http_status_code', 'response_time_ms', 'checked_at'] as $expected) {
            $this->assertContains($expected, $cols);
        }

        $this->assertNotContains('created_at', $cols);
        $this->assertNotContains('updated_at', $cols);
    }

    public function test_settings_table_has_correct_columns(): void
    {
        $cols = Schema::getColumnListing('settings');

        $this->assertContains('key', $cols);
        $this->assertContains('value', $cols);
    }

    /**
     * B4: ported from reference/tests/Feature/migration.test.ts's "jobs
     * table has correct columns" case. The reference's custom jobs table is
     * dropped (see docs/porting-notes.md), but Laravel's own jobs table —
     * already present from the Phase 0 skeleton — carries the identical
     * seven columns, so this case still applies unmodified.
     */
    public function test_jobs_table_has_correct_columns(): void
    {
        $cols = Schema::getColumnListing('jobs');

        foreach (['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'] as $expected) {
            $this->assertContains($expected, $cols);
        }
    }

    /**
     * Proves the stated thing — a unique INDEX on apps.path — via
     * Schema::getIndexes(), not just a unique constraint violation. A
     * QueryException on duplicate insert (see
     * test_apps_path_rejects_duplicate_paths below) is consistent with a
     * unique index but doesn't distinguish it from, say, a BEFORE INSERT
     * trigger enforcing uniqueness in application-equivalent SQL.
     */
    public function test_apps_path_has_a_unique_index(): void
    {
        $indexes = Schema::getIndexes('apps');

        $hasUniquePathIndex = collect($indexes)->contains(
            fn (array $index) => $index['unique'] && $index['columns'] === ['path']
        );

        $this->assertTrue($hasUniquePathIndex, 'Expected a unique index on apps.path.');
    }

    public function test_apps_path_rejects_duplicate_paths(): void
    {
        App::create(['name' => 'First', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/dupe']);

        $this->expectException(QueryException::class);

        App::create(['name' => 'Second', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/dupe']);
    }

    public function test_sqlite_foreign_key_enforcement_is_enabled(): void
    {
        $result = DB::select('PRAGMA foreign_keys');

        $this->assertSame(1, (int) $result[0]->foreign_keys);
    }

    public function test_deleting_an_app_cascades_to_deployments_and_health_checks(): void
    {
        $app = App::create(['name' => 'Cascade', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/cascade-feature']);
        $deployment = Deployment::create(['app_id' => $app->id, 'status' => 'pending']);
        $healthCheck = HealthCheck::record($app->id, 'up', 200, 50);

        $app->delete();

        $this->assertDatabaseMissing('deployments', ['id' => $deployment->id]);
        $this->assertDatabaseMissing('health_checks', ['id' => $healthCheck->id]);
    }
}
