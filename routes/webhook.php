<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Loaded via bootstrap/app.php's withRouting(then: ...) by a bare `require`
// — deliberately NOT wrapped in Route::middleware('web')->group(...) or
// folded into routes/api.php. See routes/web.php for the full reasoning.
//
// GitHub's webhook POST is public, machine-to-machine, and carries no CSRF
// token. It must stay outside the `web` group (which carries
// PreventRequestForgery) and outside the `/api` prefix (its URI has none).
// It ends up with only the framework's global middleware — no CSRF, no
// throttle:api, no session.
//
// Verified with `php artisan route:list --path=webhook -v`: no `web`
// group, no PreventRequestForgery in the resolved stack.

Route::post('/apps/{id}/webhook', [WebhookController::class, 'handle'])
    ->name('apps.webhook');
