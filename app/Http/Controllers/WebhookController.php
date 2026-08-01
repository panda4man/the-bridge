<?php

namespace App\Http\Controllers;

use App\Filament\Actions\DeployAction;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * GitHub webhook receiver. Ported from reference/src/routes/apps.ts:212-239
 * (POST /apps/:id/webhook).
 *
 * Registered in routes/webhook.php, loaded outside both the `web` and
 * `api` middleware groups — see that file and routes/web.php for why.
 */
class WebhookController extends Controller
{
    public function handle(Request $request, string $id): JsonResponse|Response
    {
        $app = App::query()->find($id);

        if (! $app) {
            return response()->json(['error' => 'Not found'], 404);
        }

        if (! $app->webhook_secret) {
            return response()->json(['error' => 'No webhook secret configured.'], 400);
        }

        $signature = $request->header('X-Hub-Signature-256');

        if (! $signature) {
            return response()->json(['error' => 'Missing signature'], 401);
        }

        // HMAC over the exact bytes GitHub sent. Re-encoding a decoded body
        // would change the bytes and every signature would fail.
        $raw = $request->getContent();
        $expected = 'sha256='.hash_hmac('sha256', $raw, $app->webhook_secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        // If the body isn't valid JSON, the reference catches the parse
        // error and proceeds with an empty payload — no `ref`, so no
        // branch to compare, so it deploys. That's deliberate reference
        // behaviour (a validly-signed request with a non-JSON body still
        // deploys) and is ported as-is here, not "fixed" into a 400.
        $payload = json_decode($raw, true);
        $ref = is_array($payload) ? ($payload['ref'] ?? null) : null;
        $pushedBranch = is_string($ref) ? Str::after($ref, 'refs/heads/') : null;

        // Mirrors the reference's JS truthiness check (`pushedBranch &&
        // ...`): only a missing ref or an empty string skip the compare,
        // not PHP's broader "falsy" notion (which would also catch the
        // branch name "0").
        if ($pushedBranch !== null && $pushedBranch !== '' && $pushedBranch !== $app->branch) {
            return response()->json([
                'skipped' => true,
                'reason' => "Push was to {$pushedBranch}, app tracks {$app->branch}",
            ]);
        }

        DeployAction::queue($app);

        return response()->noContent();
    }
}
