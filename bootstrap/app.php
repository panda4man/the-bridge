<?php

use App\Http\Middleware\RequireApiToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // `then` runs after web/api/health are registered. Both `require`s
        // below are deliberately bare — not Route::middleware('web')->
        // group(...) — so the GitHub webhook route and the parity-path app
        // routes land with no `web` group (no CSRF) and no `/api` prefix.
        // See routes/webhook.php and routes/parity.php.
        then: function (): void {
            require __DIR__.'/../routes/webhook.php';
            require __DIR__.'/../routes/parity.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.token' => RequireApiToken::class,
        ]);

        // Laravel 13 moved TrimStrings/ConvertEmptyStringsToNull into the
        // GLOBAL middleware stack (Middleware::getGlobalMiddleware()) —
        // earlier versions scoped TrimStrings to the `web` group only. That
        // means it silently ran on ParityController's env-write endpoint too
        // (routes/parity.php, outside both `web` and `api`), stripping the
        // exact trailing whitespace/newlines a `.env` file legitimately
        // depends on before AppProvisioner::writeEnvFile() ever saw the
        // content — a real parity break the reference's writeFileSync(...,
        // content, 'utf-8') never has, caught by
        // tests/Feature/ParityRoutesTest.php's non-empty-write case
        // asserting byte-identical round-trip. `content` is the only
        // request field this port ever uses for raw file contents, so it is
        // excepted globally rather than per-route (global is the only
        // granularity trimStrings()/TrimStrings::except() offer — see
        // vendor/laravel/framework/.../TrimStrings.php).
        $middleware->trimStrings(except: ['content']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
