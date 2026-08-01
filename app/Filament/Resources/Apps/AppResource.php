<?php

namespace App\Filament\Resources\Apps;

use App\Filament\Resources\Apps\Pages\CreateApp;
use App\Filament\Resources\Apps\Pages\EditApp;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Filament\Resources\Apps\Pages\ViewApp;
use App\Filament\Resources\Apps\RelationManagers\DeploymentsRelationManager;
use App\Filament\Resources\Apps\Schemas\AppForm;
use App\Filament\Resources\Apps\Schemas\AppInfolist;
use App\Filament\Resources\Apps\Tables\AppsTable;
use App\Models\App;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AppResource extends Resource
{
    protected static ?string $model = App::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return AppForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AppInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AppsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DeploymentsRelationManager::class,
        ];
    }

    /**
     * The apps list is the panel's ROOT page, and the rest of the resource
     * lives under `/apps` — exactly the URL shape the Express app served
     * (`GET /` is the app list; `/apps/create`, `/apps/:id`, `/apps/:id/edit`
     * are the others), so existing bookmarks and the documented webhook URL
     * keep working.
     *
     * Filament derives resource URLs as `panel path + slug + route path`, so
     * an empty slug plus explicitly `/apps`-prefixed sub-routes is what
     * produces that shape. The side effect is a doubled dot in the generated
     * route names (`filament.admin.resources..index`); harmless, since
     * nothing resolves these by literal name, but do not "tidy" the slug
     * without checking every URL above.
     *
     * The panel's Dashboard page is deliberately NOT registered — see
     * App\Providers\Filament\AdminPanelProvider. Two pages cannot both own
     * `/`.
     */
    protected static ?string $slug = '/';

    public static function getPages(): array
    {
        return [
            'index' => ListApps::route('/'),
            'create' => CreateApp::route('/apps/create'),
            'view' => ViewApp::route('/apps/{record}'),
            'edit' => EditApp::route('/apps/{record}/edit'),
        ];
    }
}
