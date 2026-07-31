<?php

namespace Tests\Unit;

use App\Enums\HealthStatus;
use App\Models\App;
use App\Models\HealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ported from reference/tests/Unit/healthCheck.test.ts, case for case.
 *
 * consecutiveFailures() has zero callers in the reference and is
 * deliberately not ported (see docs/porting-notes.md and app/Models/HealthCheck.php),
 * so the reference's third case ("consecutiveFailures counts only trailing
 * failures") is intentionally not mirrored here.
 */
class HealthCheckModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_and_find_latest_for_app(): void
    {
        $app = App::create(['name' => 'HC', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/hc']);

        HealthCheck::record($app->id, HealthStatus::Up->value, 200, 120);

        $latest = HealthCheck::findLatest($app->id);

        $this->assertNotNull($latest);
        $this->assertSame(HealthStatus::Up, $latest->status);
        $this->assertSame(200, $latest->http_status_code);
        $this->assertSame(120, $latest->response_time_ms);
    }

    public function test_list_recent_returns_up_to_20_results_newest_first(): void
    {
        $app = App::create(['name' => 'HC2', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/hc2']);

        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = HealthCheck::record($app->id, HealthStatus::Up->value, 200, $i * 10)->id;
        }

        $results = HealthCheck::listRecent($app->id);

        $this->assertCount(20, $results);
        // Newest-first: the 20 highest ids, in descending order.
        $this->assertSame(array_slice(array_reverse($ids), 0, 20), $results->pluck('id')->all());
    }

    /**
     * Explicit prune coverage called out in the Phase 1 spec: insert 25,
     * assert 20 remain, and that they are the newest 20 (ids 6..30 if the
     * first record's id is 1).
     */
    public function test_record_prunes_to_the_20_most_recent_rows(): void
    {
        $app = App::create(['name' => 'HC-prune', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/hc-prune']);

        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = HealthCheck::record($app->id, HealthStatus::Up->value, 200, $i * 10)->id;
        }

        $remainingIds = HealthCheck::query()
            ->where('app_id', $app->id)
            ->orderByDesc('id')
            ->pluck('id')
            ->all();

        $this->assertCount(20, $remainingIds);
        $this->assertSame(array_slice(array_reverse($ids), 0, 20), $remainingIds);
    }

    /**
     * B2: the prune in record() must be scoped to the app being recorded
     * for on BOTH sides — the outer DELETE and the keep-set subquery.
     * RefreshDatabase gives every other test a clean DB with a single app,
     * which structurally cannot catch a global prune (losing either
     * `app_id` filter wipes every other app's health history on every
     * check). Phase 2's HealthPoller calls record() on a timer for every
     * app, so this is a real scenario, not a hypothetical.
     */
    public function test_record_prune_does_not_touch_other_apps_health_checks(): void
    {
        $appA = App::create(['name' => 'HC-prune-a', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/hc-prune-a']);
        $appB = App::create(['name' => 'HC-prune-b', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/hc-prune-b']);

        $bIds = [];
        for ($i = 0; $i < 3; $i++) {
            $bIds[] = HealthCheck::record($appB->id, HealthStatus::Up->value, 200, $i)->id;
        }

        for ($i = 0; $i < 25; $i++) {
            HealthCheck::record($appA->id, HealthStatus::Up->value, 200, $i * 10);
        }

        $aRemaining = HealthCheck::query()->where('app_id', $appA->id)->count();
        $bRemaining = HealthCheck::query()->where('app_id', $appB->id)->orderBy('id')->pluck('id')->all();

        $this->assertSame(20, $aRemaining);
        $this->assertSame($bIds, $bRemaining);
    }

    /**
     * B2, second angle: the previous test's ordering (all of B's rows
     * predate all of A's) only ever exercises the OUTER app_id filter —
     * B's ids are always older/smaller than A's, so they never compete for
     * a spot in a table-wide "most recent" ranking, and a bug in just the
     * INNER keep-set subquery's app_id filter would go unnoticed there.
     * Interleaving so both apps keep producing fresh (larger) ids while
     * the other is actively above the 20-row threshold forces each app's
     * keep-set to be ranked within its own id history, not globally.
     */
    public function test_record_prune_ranks_within_the_owning_app_even_when_interleaved(): void
    {
        $appA = App::create(['name' => 'HC-interleave-a', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/hc-interleave-a']);
        $appB = App::create(['name' => 'HC-interleave-b', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/hc-interleave-b']);

        $idsA = [];
        $idsB = [];
        for ($round = 0; $round < 30; $round++) {
            $idsA[] = HealthCheck::record($appA->id, HealthStatus::Up->value, 200, $round)->id;
            $idsB[] = HealthCheck::record($appB->id, HealthStatus::Up->value, 200, $round)->id;
        }

        $remainingA = HealthCheck::query()->where('app_id', $appA->id)->orderByDesc('id')->pluck('id')->all();
        $remainingB = HealthCheck::query()->where('app_id', $appB->id)->orderByDesc('id')->pluck('id')->all();

        $this->assertSame(array_slice(array_reverse($idsA), 0, 20), $remainingA);
        $this->assertSame(array_slice(array_reverse($idsB), 0, 20), $remainingB);
    }

    public function test_health_checks_are_deleted_when_owning_app_is_deleted(): void
    {
        $app = App::create(['name' => 'HC-cascade', 'repo_url' => 'r', 'branch' => 'main', 'path' => '/hc-cascade']);
        $check = HealthCheck::record($app->id, HealthStatus::Down->value, 500, 10);

        $app->delete();

        $this->assertNull(HealthCheck::find($check->id));
    }
}
