<?php

namespace App\Http\Controllers;

use App\Filament\Actions\DeployAction;
use App\Models\App;
use App\Models\Deployment;
use App\Services\AppProvisioner;
use App\Services\ContainerStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The reference's four `/apps/:id/...` endpoints that were never namespaced
 * under `/api`: GET+POST `/apps/{id}/env`, GET `/apps/{id}/containers`,
 * POST `/apps/{id}/deploy`, POST `/apps/{id}/rollback`. Ported from
 * reference/src/routes/apps.ts:151-211 at the reference's exact, verbatim
 * paths.
 *
 * Registered in routes/parity.php, loaded outside both the `web` and `api`
 * groups (see that file for why) and behind the `api.token` bearer-auth
 * middleware — a deliberate addition over the reference, which has no
 * authentication anywhere (it was a trusted-network Express app). Porting
 * these unauthenticated would leave POST /apps/{id}/env writing arbitrary
 * content into a production app's `.env`, and GET reading those secrets back
 * out, for anyone who can reach the host. Bearer auth keeps the URLs
 * byte-identical to the reference (no /api prefix, which is why these can't
 * just live in Api\AppsController) while putting a credential in front of
 * the secret material.
 *
 * Deviation from the reference, deliberate and noted per-method below: the
 * reference sends a plain-text 404 body on deploy/rollback ("Not found",
 * not JSON) and 302-redirects to /deployments/:id on success for both
 * deploy and rollback, since it renders an EJS page there. There is no page
 * for a bearer-token-authenticated machine caller to land on — every
 * response on every path in this controller is JSON instead, mirroring
 * Api\AppsController::deploy's 202 body shape exactly for the two
 * enqueue endpoints.
 */
class ParityController extends Controller
{
    /**
     * GET /apps/{id}/env. Ported from reference/src/routes/apps.ts:151-161.
     *
     * A missing `.env` reads as `""`, not a 404 — AppProvisioner::readEnvFile()
     * already encodes that. Only a genuine read failure (e.g. the file exists
     * but isn't readable) reaches the catch below.
     */
    public function env(string $id): JsonResponse
    {
        $app = App::query()->find($id);

        if (! $app) {
            return response()->json(['error' => 'Not found'], 404);
        }

        try {
            $content = app(AppProvisioner::class)->readEnvFile($app->path);
        } catch (Throwable) {
            return response()->json(['error' => 'Could not read .env file'], 500);
        }

        return response()->json(['content' => $content]);
    }

    /**
     * POST /apps/{id}/env. Ported from reference/src/routes/apps.ts:163-178.
     *
     * The refusal is the parity-critical part: anything that isn't a
     * non-empty-after-trim string is rejected with 400 and the existing file
     * is left byte-for-byte untouched — AppProvisioner::writeEnvFile() is
     * never called on that path. This mirrors
     * App\Filament\Actions\EditEnvFileAction's `required` field for exactly
     * the same reason (see that class's docblock): an accidental
     * empty-content write to a production `.env` is unrecoverable.
     */
    public function updateEnv(Request $request, string $id): JsonResponse
    {
        $app = App::query()->find($id);

        if (! $app) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $content = $request->input('content');

        if (! is_string($content) || trim($content) === '') {
            return response()->json(['error' => 'Content must be a non-empty string'], 400);
        }

        try {
            app(AppProvisioner::class)->writeEnvFile($app->path, $content);
        } catch (Throwable) {
            return response()->json(['error' => 'Could not write .env file'], 500);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * GET /apps/{id}/containers. Ported from reference/src/routes/apps.ts:188-192.
     */
    public function containers(string $id, ContainerStatus $containerStatus): JsonResponse
    {
        $app = App::query()->find($id);

        if (! $app) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json(['containers' => $containerStatus->forWorkDir($app->path)]);
    }

    /**
     * POST /apps/{id}/deploy. Ported from reference/src/routes/apps.ts:180-186.
     *
     * DeployAction::queue() is the single shared enqueue path — also used by
     * the panel, the GitHub webhook, and Api\AppsController::deploy(). Do
     * not re-create the pending row and dispatch by hand here.
     *
     * Deviation from the reference: 302 redirect to /deployments/:id becomes
     * 202 JSON, {deployment_id, app_id, status} — the exact shape
     * Api\AppsController::deploy() already returns, so a caller doesn't need
     * to know which of the two paths it hit.
     */
    public function deploy(string $id): JsonResponse
    {
        $app = App::query()->find($id);

        if (! $app) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $deployment = DeployAction::queue($app);

        return response()->json([
            'deployment_id' => $deployment->id,
            'app_id' => $app->id,
            'status' => $deployment->status->value,
        ], 202);
    }

    /**
     * POST /apps/{id}/rollback. Ported from reference/src/routes/apps.ts:194-210.
     *
     * Three guards, in the reference's order:
     *
     * 1. App not found → 404.
     * 2. Source deployment not found OR `source.app_id !== app.id` → 404.
     *    THE cross-app check is the reason this endpoint exists as its own
     *    controller method rather than a thin wrapper around
     *    App\Filament\Actions\RollbackAction: this endpoint takes BOTH ids
     *    from the caller, so — unlike the panel action, which reads the app
     *    off the source deployment and structurally cannot disagree with
     *    itself — a caller could otherwise roll app A back to app B's
     *    commit. See docs/porting-notes.md's "Rollback: which of the
     *    reference's three guards survived" section.
     * 3. `!source.commit_sha` → 400, with the reference's exact em-dash
     *    wording.
     *
     * Unlike App\Filament\Actions\RollbackAction, this endpoint does NOT
     * restrict to successful source deployments — the reference's route
     * never enforced that either; only its view hid the button on
     * non-successful rows. That stayed a Phase-4 visibility rule, not a
     * route-level refusal, and this endpoint matches the route.
     *
     * On success, delegates to DeployAction::queue($app, $rollbackSha) —
     * the same shared enqueue path the deploy endpoint above uses — rather
     * than creating the row and dispatching by hand a third time.
     */
    public function rollback(Request $request, string $id): JsonResponse
    {
        $app = App::query()->find($id);

        if (! $app) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $source = Deployment::query()->find($request->input('deployment_id'));

        if (! $source || $source->app_id !== $app->id) {
            return response()->json(['error' => 'Not found'], 404);
        }

        if (! $source->commit_sha) {
            return response()->json(['error' => 'Deployment has no commit SHA — cannot rollback.'], 400);
        }

        $deployment = DeployAction::queue($app, $source->commit_sha);

        return response()->json([
            'deployment_id' => $deployment->id,
            'app_id' => $app->id,
            'status' => $deployment->status->value,
        ], 202);
    }
}
