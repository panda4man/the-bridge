<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Ported from reference/src/routes/api.ts:14-27 (GET /branches).
 *
 * Public — not behind api.token. Always answers 200: a failed
 * `git ls-remote` is reported through the optional `error` key, never a
 * non-2xx status, because a live consumer (the Filament branch Select —
 * Phase 4 — and bridge-mcp) treats this endpoint as never-throwing.
 */
class BranchesController extends Controller
{
    public function index(Request $request, GitService $git): JsonResponse
    {
        $repoUrl = trim((string) $request->query('repo_url', ''));

        if ($repoUrl === '') {
            return response()->json(['branches' => []]);
        }

        try {
            $branches = $git->lsRemote($repoUrl);
            sort($branches);

            $priority = array_values(array_filter(
                ['main', 'master'],
                fn (string $branch): bool => in_array($branch, $branches, true),
            ));

            $rest = array_values(array_filter(
                $branches,
                fn (string $branch): bool => ! in_array($branch, $priority, true),
            ));

            return response()->json(['branches' => [...$priority, ...$rest]]);
        } catch (Throwable) {
            return response()->json(['branches' => [], 'error' => 'Failed to fetch branches']);
        }
    }
}
