<?php

namespace App\Filament\Resources\Apps\RelationManagers;

use App\Filament\Resources\Apps\Resources\Deployments\DeploymentResource;
use App\Filament\Resources\Deployments\Tables\DeploymentsTable;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The same read-only deployments table, scoped to one app, on the app view
 * page — mirroring reference/src/views/apps/show.ejs, which lists an app's
 * deployments inline.
 *
 * Reuses DeploymentsTable::configure() rather than redefining the columns:
 * the SHA truncation, duration, and status badge are the same rendering in
 * both places, and the Rollback action must behave identically wherever it is
 * offered. The only difference is dropping the `app.name` column, which is
 * constant within an app's own list.
 *
 * $relatedResource points ViewAction at the nested DeploymentResource's view
 * page instead of Filament's default (which, unset, falls back to nothing
 * usable here) — see RelationManager::getDefaultActionUrl(). The "View all"
 * header action is the only way to reach the nested index page/full history;
 * nothing links there automatically.
 */
class DeploymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'deployments';

    protected static ?string $title = 'Deployments';

    protected static ?string $relatedResource = DeploymentResource::class;

    public function table(Table $table): Table
    {
        return DeploymentsTable::configure($table, withAppColumn: false)
            ->recordTitleAttribute('id')
            ->headerActions([
                Action::make('viewAllDeployments')
                    ->label('View all')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->url(fn (): string => DeploymentResource::getUrl('index', ['app' => $this->getOwnerRecord()])),
            ]);
    }
}
