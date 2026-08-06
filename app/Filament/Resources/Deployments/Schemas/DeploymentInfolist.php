<?php

namespace App\Filament\Resources\Deployments\Schemas;

use App\Filament\Resources\Apps\AppResource;
use App\Livewire\DeploymentLog;
use App\Models\Deployment;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The deployment detail view.
 *
 * The log section is App\Livewire\DeploymentLog, which polls and appends
 * incrementally; it is not a TextEntry over the `log` column, because that
 * renders the log as it stood when the page loaded and never moves again.
 * Filament passes the record to it automatically
 * (Filament\Schemas\Components\Livewire::getComponentProperties()).
 */
class DeploymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Deployment')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('app.name')
                            ->label('App')
                            ->url(fn (Deployment $record): string => AppResource::getUrl('view', ['record' => $record->app])),

                        TextEntry::make('status')
                            ->badge(),

                        TextEntry::make('duration')
                            ->placeholder('—')
                            ->state(fn (Deployment $record): ?string => $record->durationText()),

                        TextEntry::make('commit_sha')
                            ->label('Commit')
                            ->placeholder('—')
                            ->fontFamily('mono')
                            ->copyable(),

                        TextEntry::make('commit_message')
                            ->label('Message')
                            ->placeholder('—')
                            ->columnSpan(2),

                        TextEntry::make('rollback_sha')
                            ->label('Rolling back to')
                            ->placeholder('—')
                            ->fontFamily('mono'),

                        TextEntry::make('started_at')
                            ->dateTime()
                            ->placeholder('—'),

                        TextEntry::make('finished_at')
                            ->dateTime()
                            ->placeholder('—'),
                    ]),

                Section::make('Log')
                    ->schema([
                        Livewire::make(DeploymentLog::class)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
