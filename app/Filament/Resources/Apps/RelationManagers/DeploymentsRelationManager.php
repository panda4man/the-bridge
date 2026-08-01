<?php

namespace App\Filament\Resources\Apps\RelationManagers;

use App\Filament\Resources\Deployments\Tables\DeploymentsTable;
use Filament\Resources\RelationManagers\RelationManager;
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
 */
class DeploymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'deployments';

    protected static ?string $title = 'Deployments';

    public function table(Table $table): Table
    {
        return DeploymentsTable::configure($table, withAppColumn: false)
            ->recordTitleAttribute('id');
    }
}
