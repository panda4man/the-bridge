<?php

namespace App\Filament\Actions;

use App\Models\App;
use App\Services\AppProvisioner;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;

/**
 * Edit the app's `.env` in place. Ported from
 * reference/src/routes/apps.ts:147-176.
 *
 * The empty-content refusal is the parity-critical part: the reference
 * returns 400 for anything that is not a non-empty string AND leaves the
 * existing file untouched (reference/tests/Feature/envEditor.test.ts). An
 * accidental select-all-delete on a production `.env` is unrecoverable, so
 * the check is `required` on the field rather than a silent write of ''.
 */
final class EditEnvFileAction
{
    public static function make(string $name = 'editEnvFile'): Action
    {
        return Action::make($name)
            ->label('Edit .env')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('gray')
            ->modalHeading('Edit .env')
            ->modalSubmitActionLabel('Save')
            ->fillForm(static fn (App $record): array => [
                'content' => app(AppProvisioner::class)->readEnvFile($record->path),
            ])
            ->schema([
                Textarea::make('content')
                    ->label('.env')
                    ->rows(18)
                    // Mirrors the reference's `typeof content !== 'string' ||
                    // content.trim().length === 0` rejection.
                    ->required()
                    ->rules(['string'])
                    ->columnSpanFull(),
            ])
            ->action(static function (App $record, array $data): void {
                app(AppProvisioner::class)->writeEnvFile($record->path, (string) $data['content']);
            })
            ->successNotificationTitle('.env saved.');
    }
}
