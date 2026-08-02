<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AppsController;
use App\Http\Controllers\Api\BranchesController;
use App\Http\Controllers\Api\DeploymentsController;
use App\Http\Controllers\Api\DocsController;
use App\Http\Controllers\ParityController;
use App\Http\Controllers\WebhookController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Plan verification #3 — "all inventoried endpoints resolve with matching
 * method and path", and "explicitly assert /apps/{id}/webhook is not shadowed
 * by the root-mounted panel".
 *
 * This inspects the route TABLE rather than issuing requests, because that is
 * where the failure mode lives: RouteCollection is keyed by method+URI, the
 * panel registers first and routes/*.php load after, so a colliding
 * declaration replaces the panel's route and erases its name with no error
 * anywhere (see the header of routes/web.php). Status codes and headers for
 * these same endpoints are pinned by ApiDeployTest, ParityRoutesTest and
 * WebhooksTest; what none of them can see is a route that resolved to the
 * wrong handler or a panel route that stopped existing.
 *
 * The reference's remaining endpoints have no HTTP route in the port by
 * design — they became Livewire interactions inside the panel, and are listed
 * in `test_the_reference_endpoints_that_became_livewire_are_deliberately_absent`
 * so that the inventory stays complete after `reference/` was deleted.
 */
class RouteParityTest extends TestCase
{
    /**
     * The reference's routes that are still real HTTP routes here, with the
     * handler each must resolve to. Paths are verbatim from the Express app
     * (reference/src/routes/{api,apps,deployments,settings}.ts).
     *
     * @return array<string, array{string, string, string|null}>
     */
    public static function survivingEndpoints(): array
    {
        return [
            'GET /' => ['GET', '/', null],
            'GET /apps/create' => ['GET', '/apps/create', null],
            'GET /apps/{id}' => ['GET', '/apps/1', null],
            'GET /apps/{id}/edit' => ['GET', '/apps/1/edit', null],
            'GET /deployments/{id}' => ['GET', '/deployments/1', null],
            'GET /settings' => ['GET', '/settings', null],

            'GET /apps/{id}/env' => ['GET', '/apps/1/env', ParityController::class.'@env'],
            'POST /apps/{id}/env' => ['POST', '/apps/1/env', ParityController::class.'@updateEnv'],
            'GET /apps/{id}/containers' => ['GET', '/apps/1/containers', ParityController::class.'@containers'],
            'POST /apps/{id}/deploy' => ['POST', '/apps/1/deploy', ParityController::class.'@deploy'],
            'POST /apps/{id}/rollback' => ['POST', '/apps/1/rollback', ParityController::class.'@rollback'],
            'POST /apps/{id}/webhook' => ['POST', '/apps/1/webhook', WebhookController::class.'@handle'],

            'GET /api/apps' => ['GET', '/api/apps', AppsController::class.'@index'],
            'POST /api/apps/{id}/deploy' => ['POST', '/api/apps/1/deploy', AppsController::class.'@deploy'],
            'GET /api/deployments/{id}' => ['GET', '/api/deployments/1', DeploymentsController::class.'@show'],
            'GET /api/deployments/{id}/log' => ['GET', '/api/deployments/1/log', DeploymentsController::class.'@log'],
            'GET /api/branches' => ['GET', '/api/branches', BranchesController::class.'@index'],
            'GET /api/openapi.json' => ['GET', '/api/openapi.json', DocsController::class.'@schema'],
            'GET /api/docs' => ['GET', '/api/docs', DocsController::class.'@ui'],
        ];
    }

    #[DataProvider('survivingEndpoints')]
    public function test_every_surviving_reference_endpoint_resolves_to_its_own_handler(
        string $method,
        string $uri,
        ?string $expectedAction,
    ): void {
        $route = $this->matchOrFail($method, $uri);

        if ($expectedAction !== null) {
            $this->assertSame($expectedAction, $route->getActionName(), "{$method} {$uri} resolved to the wrong handler");

            return;
        }

        // The panel's own pages. Asserting the handler would pin a Filament
        // internal; asserting the route NAME is what the collision erases.
        $this->assertStringStartsWith('filament.', (string) $route->getName(), "{$method} {$uri} is no longer served by the panel");
    }

    public function test_the_webhook_is_not_shadowed_by_the_panel_and_carries_no_csrf_protection(): void
    {
        $route = $this->matchOrFail('POST', '/apps/1/webhook');

        $this->assertSame(WebhookController::class.'@handle', $route->getActionName());
        $this->assertSame('apps.webhook', $route->getName());
        $this->assertNotContains(PreventRequestForgery::class, $this->middlewareOf($route));
        $this->assertNotContains('web', $route->gatherMiddleware());
    }

    public function test_the_panel_still_owns_its_auth_routes(): void
    {
        // The specific casualty documented in routes/web.php: a colliding
        // declaration deletes this name, and the panel then throws
        // RouteNotFoundException on its next anonymous redirect.
        $this->assertNotNull(Route::getRoutes()->getByName('filament.admin.auth.login'));
        $this->assertNotNull(Route::getRoutes()->getByName('filament.admin.auth.logout'));
        $this->assertNotNull(Route::getRoutes()->getByName('filament.admin.pages.settings'));
    }

    public function test_the_parity_and_api_routes_are_all_token_guarded(): void
    {
        $guarded = [
            ['GET', '/apps/1/env'],
            ['POST', '/apps/1/env'],
            ['GET', '/apps/1/containers'],
            ['POST', '/apps/1/deploy'],
            ['POST', '/apps/1/rollback'],
            ['GET', '/api/apps'],
            ['POST', '/api/apps/1/deploy'],
            ['GET', '/api/deployments/1'],
            ['GET', '/api/deployments/1/log'],
        ];

        foreach ($guarded as [$method, $uri]) {
            $this->assertContains(
                'api.token',
                $this->matchOrFail($method, $uri)->gatherMiddleware(),
                "{$method} {$uri} is not behind the API token middleware",
            );
        }
    }

    public function test_the_openapi_and_docs_endpoints_stay_unauthenticated(): void
    {
        foreach ([['GET', '/api/openapi.json'], ['GET', '/api/docs']] as [$method, $uri]) {
            $this->assertNotContains(
                'api.token',
                $this->matchOrFail($method, $uri)->gatherMiddleware(),
                "{$method} {$uri} became authenticated; the reference serves it to anyone",
            );
        }
    }

    public function test_no_sse_route_survives(): void
    {
        // reference/src/routes/deployments.ts:GET /deployments/:id/stream.
        // Replaced by the Livewire poller in Phase 6; plan verification #6.
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('stream', $route->uri(), 'an SSE-shaped route survived');
        }
    }

    public function test_the_reference_endpoints_that_became_livewire_are_deliberately_absent(): void
    {
        // Each of these was a form POST or an EventSource GET in the Express
        // app and is now a Filament action or component method, reached
        // through Livewire's update endpoint rather than its own URI. The
        // behaviour is covered by the Filament feature tests named alongside.
        $replaced = [
            ['POST', '/apps', 'Filament\CreateAppTest'],
            ['PUT', '/apps/1', 'Filament\EditAppTest'],
            ['DELETE', '/apps/1', 'Filament\AppActionsTest'],
            ['POST', '/apps/1/webhook-secret', 'Filament\AppActionsTest'],
            ['POST', '/settings', 'Filament\SettingsPageTest'],
            ['POST', '/deployments/1/reset', 'Filament\ResetDeploymentTest'],
            ['GET', '/deployments/1/stream', 'Filament\DeploymentLogTest'],
        ];

        foreach ($replaced as [$method, $uri, $covers]) {
            $this->assertNull(
                $this->match($method, $uri),
                "{$method} {$uri} exists as an HTTP route; the port serves it through Livewire ({$covers})",
            );
        }
    }

    private function match(string $method, string $uri): ?RoutingRoute
    {
        try {
            return Route::getRoutes()->match(Request::create($uri, $method));
        } catch (\Throwable) {
            return null;
        }
    }

    private function matchOrFail(string $method, string $uri): RoutingRoute
    {
        $route = $this->match($method, $uri);

        $this->assertNotNull($route, "{$method} {$uri} does not resolve to any route");

        return $route;
    }

    /**
     * @return list<string>
     */
    private function middlewareOf(RoutingRoute $route): array
    {
        return array_values(array_filter(
            $route->gatherMiddleware(),
            static fn ($middleware): bool => is_string($middleware),
        ));
    }
}
