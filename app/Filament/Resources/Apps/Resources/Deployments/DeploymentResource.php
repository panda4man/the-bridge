<?php

namespace App\Filament\Resources\Apps\Resources\Deployments;

use App\Filament\Resources\Apps\AppResource;
use App\Filament\Resources\Apps\Resources\Deployments\Pages\ListDeployments;
use App\Filament\Resources\Apps\Resources\Deployments\Pages\ViewDeployment;
use App\Filament\Resources\Deployments\Schemas\DeploymentInfolist;
use App\Filament\Resources\Deployments\Tables\DeploymentsTable;
use App\Models\Deployment;
use BackedEnum;
use Closure;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Resources\ResourceConfiguration;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/**
 * One app's deployment history, nested under it: /apps/{app}/deployments and
 * /apps/{app}/deployments/{record}. $parentResource is the only thing that
 * makes this nested rather than standalone — Filament derives the route
 * prefix, breadcrumbs, and query scoping from it via the deployments()/app()
 * relationship pair already on Deployment/App (see
 * Filament\Resources\Resource\Concerns\BelongsToParent), no manual
 * getEloquentQuery() override needed.
 *
 * Reuses the global DeploymentResource's table/infolist verbatim —
 * DeploymentsTable::configure()'s withAppColumn param exists for exactly
 * this. Read-only, same as the global resource: deployments are created by
 * the deploy/rollback/branch-change/webhook paths, never by hand. The global
 * `App\Filament\Resources\Deployments\DeploymentResource` is untouched and
 * still works; this is additive.
 */
class DeploymentResource extends Resource
{
    protected static ?string $model = Deployment::class;

    protected static ?string $parentResource = AppResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $recordTitleAttribute = 'id';

    public static function infolist(Schema $schema): Schema
    {
        return DeploymentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeploymentsTable::configure($table, withAppColumn: false);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeployments::route('/'),
            'view' => ViewDeployment::route('/{record}'),
        ];
    }

    /**
     * Filament's default nested-resource prefix is `{parentParam}/{childSlug}`,
     * derived from the PARENT's own route prefix (AppResource::getRoutePrefix()).
     * AppResource's prefix is empty — its slug is `/` so the root URL stays the
     * apps list, and every one of its OWN pages hardcodes `apps/...` in the
     * page path instead (see AppResource's $slug docblock). That means the
     * default derivation here would register `{app}/deployments`, an
     * unprefixed catch-all matching ANY first path segment — confirmed by
     * RouteParityTest to swallow `GET /api/deployments/{id}`, resolving it to
     * this resource's ViewDeployment page instead of the API controller.
     *
     * This override is vendor's own registerRoutes() (see
     * Filament\Resources\Resource\Concerns\HasRoutes), with `apps/` hardcoded
     * onto the prefix to match AppResource's actual URL convention. Everything
     * else — query scoping, breadcrumbs, the relation manager's
     * $relatedResource wiring — is untouched, since all of that keys off the
     * route PARAMETER NAME (`app`), not the prefix string.
     */
    public static function registerRoutes(Panel $panel, ?Closure $registerPageRoutes = null, ?ResourceConfiguration $configuration = null): void
    {
        $registerPageRoutes ??= function () use ($panel, $configuration): void {
            foreach (static::getPages() as $name => $page) {
                $route = $page->registerRoute($panel);

                if ($configuration) {
                    $route?->middleware("resource-configuration:{$configuration->getKey()}");
                }

                $route?->name($name);
            }
        };

        $parentResource = static::getParentResourceRegistration();

        $parentResource->getParentResource()::registerRoutes($panel, function () use ($panel, $parentResource, $registerPageRoutes): void {
            Route::name($parentResource->getRouteName().'.')
                ->prefix('apps/{'.$parentResource->getParentRouteParameterName().'}/'.static::getSlug($panel))
                ->group($registerPageRoutes);
        });
    }
}
