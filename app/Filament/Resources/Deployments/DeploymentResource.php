<?php

namespace App\Filament\Resources\Deployments;

use App\Filament\Resources\Deployments\Pages\ListDeployments;
use App\Filament\Resources\Deployments\Pages\ViewDeployment;
use App\Filament\Resources\Deployments\Schemas\DeploymentInfolist;
use App\Filament\Resources\Deployments\Tables\DeploymentsTable;
use App\Models\Deployment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only. Deployments are created by the deploy/rollback/branch-change/
 * webhook paths and written by App\Jobs\DeployApp — never by hand. There is
 * no create page, no edit page, and no form schema; the generator's
 * create/edit stubs were deleted rather than left unrouted.
 *
 * canCreate()/canEdit()/canDelete() are overridden AS WELL as the pages being
 * absent. Dropping a page only removes its route; these also stop Filament
 * rendering the buttons that would link to it, and stop the
 * DeploymentsRelationManager on the app view page from offering its own
 * create/edit/delete actions.
 */
class DeploymentResource extends Resource
{
    protected static ?string $model = Deployment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $recordTitleAttribute = 'id';

    public static function infolist(Schema $schema): Schema
    {
        return DeploymentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeploymentsTable::configure($table);
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
}
