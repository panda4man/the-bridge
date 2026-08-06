<?php

namespace App\Filament\Resources\Apps\Tables;

use App\Enums\AppStatus;
use App\Filament\Actions\DeployAction;
use App\Models\App;
use App\Models\Deployment;
use App\Models\HealthCheck;
use App\Services\AppProvisioner;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ported from reference/src/views/apps/index.ejs, which lists every app with
 * its latest health status alongside its deploy status.
 */
class AppsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('repo_url')
                    ->label('Repo URL')
                    ->searchable()
                    ->limit(48)
                    ->tooltip(fn (?string $state): ?string => $state),

                TextColumn::make('branch')
                    ->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                // Not a column — one query per row against health_checks.
                // The reference does the same (a findLatest() per app in its
                // index route), and the row count here is the operator's
                // handful of apps, not an unbounded table.
                TextColumn::make('last_health_status')
                    ->label('Health')
                    ->badge()
                    ->placeholder('—')
                    ->state(fn (App $record): ?string => HealthCheck::findLatest($record->id)?->status?->value),

                TextColumn::make('latestDeployment.created_at')
                    ->label('Last Deploy')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy(
                            Deployment::query()
                                ->select('created_at')
                                ->whereColumn('deployments.app_id', 'apps.id')
                                ->latest('created_at')
                                ->limit(1),
                            $direction
                        );
                    }),

                TextColumn::make('path')
                    ->fontFamily('mono')
                    ->limit(48)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('latestDeployment.created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(AppStatus::class),
            ])
            ->recordActions([
                DeployAction::make(),
                ViewAction::make(),
                EditAction::make(),
                // Same containers-down-then-rmdir side effect as the edit
                // page's delete — see EditApp::getHeaderActions().
                DeleteAction::make()
                    ->before(fn (App $record): mixed => app(AppProvisioner::class)->destroy($record->path))
                    ->successNotificationTitle('App removed.'),
            ]);
        // No DeleteBulkAction: bulk-deleting apps would run an unbounded
        // series of `docker compose down` calls, each with its own 60s
        // ceiling, inside one web request. Deleting an app is a one-at-a-time
        // operation by construction.
    }
}
