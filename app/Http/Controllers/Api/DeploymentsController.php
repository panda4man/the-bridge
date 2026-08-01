<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Ported from reference/src/routes/api.ts:72-104 (GET /deployments/:id,
 * GET /deployments/:id/log). Both behind api.token (routes/api.php).
 */
class DeploymentsController extends Controller
{
    /**
     * $id is a string route parameter, not int-typed — see
     * AppsController::deploy()'s docblock for why.
     */
    public function show(string $id): JsonResponse
    {
        $deployment = Deployment::query()->with('app')->find($id);

        if (! $deployment) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json([
            'id' => $deployment->id,
            'app_id' => $deployment->app_id,
            // app_name is an API contract field flattened from the belongsTo
            // relation (docs/porting-notes.md, Phase 1 "Things later phases
            // would otherwise re-derive") — never a bare column.
            'app_name' => $deployment->app?->name,
            'status' => $deployment->status->value,
            'commit_sha' => $deployment->commit_sha,
            'commit_message' => $deployment->commit_message,
            'started_at' => $deployment->started_at,
            'finished_at' => $deployment->finished_at,
            'log_length' => $deployment->logLength(),
        ]);
    }

    /**
     * Offsets are byte offsets (PHP strlen()/substr()), consistent with
     * log_length above and with Deployment::appendLog()'s own ANSI
     * stripping happening on write. docs/porting-notes.md (Phase 1) flags
     * this as a unit this port must pick and be internally consistent
     * about, not necessarily match the reference's UTF-16 code units — this
     * is that decision.
     *
     * The slicing itself lives on the model (logSlice()/logLength()) because
     * Phase 6's log viewer polls over Livewire rather than over this route —
     * it cannot send a bearer token from a panel session — and the two must
     * hand out identical offsets. See App\Livewire\DeploymentLog.
     */
    public function log(Request $request, string $id): JsonResponse|Response
    {
        $deployment = Deployment::query()->find($id);

        if (! $deployment) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $chunk = $deployment->logSlice((int) $request->query('offset', '0'));

        return response($chunk, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Log-Offset' => (string) $deployment->logLength(),
            'X-Deploy-Status' => $deployment->status->value,
            'X-Deploy-Done' => $deployment->status->isTerminal() ? 'true' : 'false',
        ]);
    }
}
