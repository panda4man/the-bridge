<?php

namespace App\Filament\Resources\Apps\Schemas;

use App\Models\App;
use App\Models\HealthCheck;
use App\Services\ContainerStatus;
use App\Services\PortBindings;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Ported from reference/src/views/apps/show.ejs.
 *
 * Three of the four sections are computed live from the host rather than read
 * from the database — port bindings parse the repo's `docker-compose.yml`,
 * container status shells `docker compose ps`. Both are defined to return []
 * on ANY failure (Phase 2: "All failures return [] silently"), so a missing
 * checkout or a down Docker daemon renders an empty section, never an error
 * page. The empty state is the correct rendering of "nothing is running".
 */
class AppInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Application')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name'),

                        TextEntry::make('status')
                            ->badge(),

                        TextEntry::make('repo_url')
                            ->label('Repo URL')
                            ->fontFamily('mono')
                            ->copyable(),

                        TextEntry::make('branch'),

                        TextEntry::make('path')
                            ->fontFamily('mono')
                            ->copyable()
                            ->columnSpanFull(),

                        TextEntry::make('health_url')
                            ->label('Health check URL')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('web_url')
                            ->label('App URL')
                            ->placeholder('—')
                            ->url(fn (App $record): ?string => $record->web_url)
                            ->openUrlInNewTab()
                            ->columnSpanFull(),
                    ]),

                Section::make('Latest health check')
                    ->schema([
                        TextEntry::make('latest_health')
                            ->hiddenLabel()
                            ->placeholder('Never checked.')
                            ->state(function (App $record): ?string {
                                $latest = HealthCheck::findLatest($record->id);

                                if (! $latest) {
                                    return null;
                                }

                                return trim(sprintf(
                                    '%s · %s · %s · %s',
                                    $latest->status instanceof \BackedEnum ? $latest->status->value : (string) $latest->status,
                                    $latest->http_status_code === null ? 'no response' : "HTTP {$latest->http_status_code}",
                                    $latest->response_time_ms === null ? '—' : "{$latest->response_time_ms}ms",
                                    (string) $latest->checked_at,
                                ));
                            }),
                    ]),

                Section::make('Port bindings')
                    ->description('Parsed from the repo\'s docker-compose.yml, with .env interpolation applied.')
                    ->schema([
                        RepeatableEntry::make('port_bindings')
                            ->hiddenLabel()
                            ->placeholder('No published ports.')
                            ->state(fn (App $record): array => PortBindings::read($record->path))
                            ->columns(4)
                            ->schema([
                                TextEntry::make('service'),
                                TextEntry::make('host_ip')->label('Host IP')->placeholder('—'),
                                TextEntry::make('host_port')->label('Host port')->placeholder('—'),
                                TextEntry::make('container_port')->label('Container port'),
                            ]),
                    ]),

                Section::make('Containers')
                    ->schema([
                        RepeatableEntry::make('containers')
                            ->hiddenLabel()
                            ->placeholder('Nothing running.')
                            ->state(fn (App $record): array => app(ContainerStatus::class)->forWorkDir($record->path))
                            ->columns(4)
                            ->schema([
                                TextEntry::make('service'),
                                TextEntry::make('name'),
                                TextEntry::make('state')->badge(),
                                TextEntry::make('status'),
                            ]),
                    ]),

                Section::make('Webhook')
                    ->description('Point a GitHub push webhook here to deploy automatically.')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('webhook_url')
                            ->label('Payload URL')
                            ->fontFamily('mono')
                            ->copyable()
                            ->state(fn (App $record): string => rtrim((string) config('app.url'), '/')."/apps/{$record->id}/webhook"),

                        TextEntry::make('webhook_secret')
                            ->label('Secret')
                            ->placeholder('Not configured.')
                            ->fontFamily('mono')
                            ->copyable()
                            ->copyableState(fn (?string $state): ?string => $state)
                            // Masked in the page source, not just visually —
                            // the full value is available through the copy
                            // action above, which is what an operator actually
                            // needs it for.
                            ->formatStateUsing(fn (?string $state): ?string => $state === null
                                ? null
                                : substr($state, 0, 6).str_repeat('•', 12)),
                    ]),
            ]);
    }
}
