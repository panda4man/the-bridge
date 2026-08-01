<?php

namespace App\Filament\Resources\Deployments\Tables;

use App\Enums\DeploymentStatus;
use App\Filament\Actions\RollbackAction;
use App\Models\Deployment;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DeploymentsTable
{
    /**
     * @param  bool  $withAppColumn  False when the table is already scoped to
     *                               one app (the DeploymentsRelationManager on
     *                               the app view page), where the column would
     *                               be the same value on every row.
     */
    public static function configure(Table $table, bool $withAppColumn = true): Table
    {
        return $table
            // Newest first. The reference lists deployments by `id DESC`
            // (src/models/deployment.ts listForApp), and an operator opening
            // this screen is almost always looking for the deploy that just
            // ran.
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                ...($withAppColumn ? [
                    TextColumn::make('app.name')
                        ->label('App')
                        ->searchable()
                        ->sortable(),
                ] : []),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('commit_sha')
                    ->label('Commit')
                    ->placeholder('—')
                    // 7 characters is git's own short-SHA width, and what the
                    // reference's deployments list renders.
                    ->formatStateUsing(fn (?string $state): ?string => $state ? substr($state, 0, 7) : null)
                    ->fontFamily('mono')
                    ->copyable()
                    ->copyableState(fn (?string $state): ?string => $state),

                TextColumn::make('commit_message')
                    ->label('Message')
                    ->placeholder('—')
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => $state),

                TextColumn::make('rollback_sha')
                    ->label('Rollback of')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => $state ? substr($state, 0, 7) : null)
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),

                // Not a column — computed from started_at/finished_at, so it
                // is neither sortable nor searchable at the SQL level.
                TextColumn::make('duration')
                    ->placeholder('—')
                    ->state(fn (Deployment $record): ?string => $record->durationText()),

                TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(DeploymentStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                RollbackAction::make(),
            ]);
        // No toolbarActions: deployments are an append-only audit trail, and
        // DeploymentResource::canDelete() is false. See its docblock.
    }
}
