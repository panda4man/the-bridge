<?php

use App\Http\Controllers\ParityController;
use Illuminate\Support\Facades\Route;

// Loaded via bootstrap/app.php's withRouting(then: ...) by a bare `require`
// — the same mechanism routes/webhook.php uses, and for the same reason:
// deliberately NOT wrapped in Route::middleware('web')->group(...) and NOT
// folded into routes/api.php.
//
// These five URIs are the reference's verbatim, un-prefixed paths
// (reference/src/routes/apps.ts:145-215) — GET/POST /apps/{id}/env,
// GET /apps/{id}/containers, POST /apps/{id}/deploy, POST
// /apps/{id}/rollback. Registering them under routes/api.php would prefix
// every one with `/api` and break parity with the reference's URLs; the
// panel already owns `/apps/{record}` and `/apps/{record}/edit` (see
// routes/web.php for the route-precedence explanation), and these use
// different trailing segments so they are distinct RouteCollection keys —
// verified with `php artisan route:list` before and after adding this file:
// every `filament.*`-named route is still present, byte-for-byte.
//
// The reference itself has no authentication anywhere — it was a
// trusted-network Express app. Porting these unauthenticated would leave
// POST /apps/{id}/env writing arbitrary content into a production app's
// .env, and GET reading those secrets back out, for anyone who can reach
// the host. This was decided with the user, not a default: all five carry
// the api.token bearer middleware (App\Http\Middleware\RequireApiToken,
// same as routes/api.php) and none carry `web`'s CSRF protection, which a
// script/webhook-style caller has no session or token to satisfy.
//
// See App\Http\Controllers\ParityController for the per-route deviations
// from the reference (everything responds JSON, including the two paths
// where the reference sends plain text or a 302 redirect — there is no EJS
// page for a bearer-token-authenticated machine caller to land on).

Route::middleware('api.token')->group(function (): void {
    Route::get('/apps/{id}/env', [ParityController::class, 'env']);
    Route::post('/apps/{id}/env', [ParityController::class, 'updateEnv']);
    Route::get('/apps/{id}/containers', [ParityController::class, 'containers']);
    Route::post('/apps/{id}/deploy', [ParityController::class, 'deploy']);
    Route::post('/apps/{id}/rollback', [ParityController::class, 'rollback']);
});
