<?php

namespace App\Filament\Actions;

use App\Models\App;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Ported from reference/src/routes/apps.ts:239-247.
 *
 * 32 random bytes, hex-encoded — 64 characters. The width is not cosmetic:
 * it is the HMAC key GitHub signs push payloads with, and Phase 5's webhook
 * route compares against it with hash_equals. Regenerating invalidates every
 * previously configured webhook for this app, which is why it confirms.
 */
final class RegenerateWebhookSecretAction
{
    public static function make(string $name = 'regenerateWebhookSecret'): Action
    {
        return Action::make($name)
            ->label('Regenerate webhook secret')
            ->icon(Heroicon::OutlinedKey)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Regenerate the webhook secret?')
            ->modalDescription('Any webhook already configured with the old secret will start failing signature verification.')
            ->modalSubmitActionLabel('Regenerate')
            ->action(static function (App $record): void {
                $record->update(['webhook_secret' => bin2hex(random_bytes(32))]);
            })
            ->successNotificationTitle('Webhook secret regenerated.');
    }
}
