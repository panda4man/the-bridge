<?php

namespace App\Http\Controllers\Api;

use App\Filament\Actions\DeployAction;
use App\Http\Controllers\Controller;
use App\Models\App;
use Illuminate\Http\JsonResponse;

/**
 * Ported from reference/src/routes/api.ts:54-70 (GET /apps,
 * POST /apps/:id/deploy). Both behind api.token (routes/api.php).
 */
class AppsController extends Controller
{
    /**
     * A bare JSON array of exactly {id, name, branch, status, repo_url} —
     * deliberately not an Eloquent API Resource collection, which wraps the
     * array in {"data": [...]} by default.
     */
    public function index(): JsonResponse
    {
        $apps = App::query()
            ->get(['id', 'name', 'branch', 'status', 'repo_url'])
            ->map(fn (App $app): array => [
                'id' => $app->id,
                'name' => $app->name,
                'branch' => $app->branch,
                'status' => $app->status->value,
                'repo_url' => $app->repo_url,
            ])
            ->all();

        return response()->json($apps);
    }

    /**
     * $id is a string, not an int-typed route parameter: a non-numeric
     * segment must miss the App lookup and fall through to the 404 below,
     * matching the reference's `Number(req.params.id)` -> NaN -> not
     * found. An int-typed parameter would 500 (TypeError) on a non-numeric
     * segment instead.
     */
    public function deploy(string $id): JsonResponse
    {
        $app = App::query()->find($id);

        if (! $app) {
            return response()->json(['error' => 'Not found'], 404);
        }

        // DeployAction::queue() is the single shared enqueue path (also
        // used by the panel and the GitHub webhook) — see
        // docs/porting-notes.md, "For Phase 5". Do not re-create the
        // pending row and dispatch by hand here.
        $deployment = DeployAction::queue($app);

        return response()->json([
            'deployment_id' => $deployment->id,
            'app_id' => $app->id,
            'status' => $deployment->status->value,
        ], 202);
    }
}
