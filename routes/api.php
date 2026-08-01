<?php

use App\Http\Controllers\Api\AppsController;
use App\Http\Controllers\Api\BranchesController;
use App\Http\Controllers\Api\DeploymentsController;
use App\Http\Controllers\Api\DocsController;
use Illuminate\Support\Facades\Route;

// Loaded via bootstrap/app.php's withRouting(api: ...) under the `/api`
// prefix and the `api` middleware group — no CSRF, no session. Verified
// against vendor/laravel/framework/.../Configuration/Middleware.php: the
// `api` group is SubstituteBindings only — no throttle:* limiter is
// attached unless something calls $middleware->throttleApi(), and
// bootstrap/app.php's withMiddleware() never does. Do not add one to
// GET /deployments/{id}/log specifically: Phase 6's log viewer polls it at
// wire:poll.1s (60/minute), exactly the default `throttle:api` ceiling, so
// enabling the default limiter would start 429ing under any jitter.
//
// Public (no api.token):
// - GET /branches, GET /openapi.json, GET /docs
//
// Token-authenticated (api.token = App\Http\Middleware\RequireApiToken):
// - GET /apps, POST /apps/{id}/deploy
// - GET /deployments/{id}, GET /deployments/{id}/log
//
// Do not register the GitHub webhook here — see routes/webhook.php. Its
// URI (`/apps/{id}/webhook`) has no `/api` prefix and it must not carry
// this group's SubstituteBindings expectations silently changed underneath
// it.
//
// Also do not register the reference's four other un-prefixed
// `/apps/{id}/...` endpoints here (env, containers, deploy, rollback at
// their reference paths) — see routes/parity.php. This file's own note
// above (from an earlier phase handoff) claimed Phase 5C would add them
// here; that was wrong. Their URIs have no `/api` prefix either, and
// putting them under this group would break parity with the reference's
// URLs the same way the webhook route would.

Route::get('/branches', [BranchesController::class, 'index']);
Route::get('/openapi.json', [DocsController::class, 'schema']);
Route::get('/docs', [DocsController::class, 'ui']);

Route::middleware('api.token')->group(function (): void {
    Route::get('/apps', [AppsController::class, 'index']);
    Route::post('/apps/{id}/deploy', [AppsController::class, 'deploy']);
    Route::get('/deployments/{id}', [DeploymentsController::class, 'show']);
    Route::get('/deployments/{id}/log', [DeploymentsController::class, 'log']);
});
