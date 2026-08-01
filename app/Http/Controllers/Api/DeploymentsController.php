<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeploymentStatus;
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
    /** @var list<DeploymentStatus> */
    private const array TERMINAL = [DeploymentStatus::Success, DeploymentStatus::Failed];

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
            'log_length' => strlen($deployment->log ?? ''),
        ]);
    }

    /**
     * Offsets are byte offsets (PHP strlen()/substr()), consistent with
     * log_length above and with Deployment::appendLog()'s own ANSI
     * stripping happening on write. docs/porting-notes.md (Phase 1) flags
     * this as a unit this port must pick and be internally consistent
     * about, not necessarily match the reference's UTF-16 code units — this
     * is that decision.
     */
    public function log(Request $request, string $id): JsonResponse|Response
    {
        $deployment = Deployment::query()->find($id);

        if (! $deployment) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $log = $deployment->log ?? '';
        $offset = max(0, (int) $request->query('offset', '0'));
        $chunk = substr($log, $offset);

        return response($chunk, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Log-Offset' => (string) strlen($log),
            'X-Deploy-Status' => $deployment->status->value,
            'X-Deploy-Done' => in_array($deployment->status, self::TERMINAL, true) ? 'true' : 'false',
        ]);
    }
}
