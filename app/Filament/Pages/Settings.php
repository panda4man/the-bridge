<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\SlackNotifier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Ported from reference/src/routes/settings.ts.
 *
 * Two distinct submissions share one POST handler in the reference,
 * discriminated by a `test_slack` field. Here they are what they actually
 * are: a Save action, and a separate "Send test notification" action.
 *
 * The reference's test path reports a FAILURE through its success flash
 * channel (`req.flash('success', 'Failed to reach Slack webhook URL.')`) —
 * listed in the plan as a defect not to carry forward. This uses a danger
 * notification, which is what Filament having one is for.
 */
class Settings extends Page
{
    protected string $view = 'filament.pages.settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 99;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'slack_webhook_url' => Setting::getValue('slack_webhook_url') ?? '',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('slack_webhook_url')
                    ->label('Slack webhook URL')
                    ->helperText('Deploy results are posted here. Leave blank to disable Slack notifications.')
                    // ->trim() BEFORE ->url(), and it matters: Filament trims
                    // as part of state mutation, so a pasted URL carrying a
                    // trailing newline is trimmed before the url rule sees it
                    // rather than being rejected as malformed.
                    ->trim()
                    ->url()
                    ->maxLength(500),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        // No trim() here: the field's own ->trim() has already done it by the
        // time the state arrives, and a second one was dead code (verified by
        // mutation — removing it left the suite green). settings.ts:18-20
        // trims at this point instead; the behaviour is the same and is
        // pinned by test_saving_trims_surrounding_whitespace, which asserts
        // the stored value rather than which layer trimmed it.
        Setting::setValue('slack_webhook_url', (string) ($state['slack_webhook_url'] ?? ''));

        Notification::make()->success()->title('Settings saved.')->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->submit('save'),

            Action::make('sendTestNotification')
                ->label('Send test notification')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                // Tests the SAVED url, not whatever is currently in the
                // input — that is the value the deploy pipeline will
                // actually use. Hidden when there is nothing saved to test,
                // mirroring settings.ts:23-24's `if (url)` guard, which
                // silently does nothing.
                ->visible(fn (): bool => (bool) Setting::getValue('slack_webhook_url'))
                ->action(function (): void {
                    $url = Setting::getValue('slack_webhook_url');

                    if (! $url) {
                        return;
                    }

                    try {
                        SlackNotifier::sendTest($url);
                    } catch (Throwable) {
                        // Deliberately no ->body($e->getMessage()): an HTTP
                        // RequestException's message embeds the request URL
                        // and the response body, which for this button means
                        // rendering the Slack webhook URL — a credential —
                        // into a toast. The title is the whole answer the
                        // operator needs.
                        Notification::make()
                            ->danger()
                            ->title('Failed to reach Slack webhook URL.')
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Test notification sent to Slack.')
                        ->send();
                }),
        ];
    }
}
