<?php

namespace App\Filament\Resources\Deployments\Schemas;

use App\Models\Deployment;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The deployment detail view.
 *
 * The live log itself is NOT here — Phase 6 replaces the placeholder entry
 * below with a polling Livewire component (`wire:poll.1s` against the log
 * endpoint Phase 5 adds, advancing an offset from `X-Log-Offset`). Until then
 * this renders the stored log as-is, which is correct for any deployment that
 * has already finished and merely stale for one still running.
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
                            ->label('App'),

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
                        TextEntry::make('log')
                            ->hiddenLabel()
                            ->placeholder('No output yet.')
                            ->extraAttributes(['class' => 'lcars-log'])
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
