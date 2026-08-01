<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\SlackNotifier;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ported from reference/tests/Feature/settings.test.ts and
 * reference/src/routes/settings.ts.
 */
class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    // --- Reference: "GET /settings returns 200" ---

    public function test_the_settings_page_loads_and_is_prefilled_with_the_saved_url(): void
    {
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/existing');

        $this->get('/settings')->assertOk();

        Livewire::test(Settings::class)
            ->assertFormSet(['slack_webhook_url' => 'https://hooks.slack.com/existing']);
    }

    public function test_the_settings_page_loads_with_no_url_saved(): void
    {
        // Blank, not null — a null into a TextInput is a Livewire binding
        // error rather than an empty field.
        Livewire::test(Settings::class)
            ->assertFormSet(['slack_webhook_url' => '']);
    }

    public function test_the_settings_page_requires_authentication(): void
    {
        auth()->logout();

        $this->get('/settings')->assertRedirect('/login');
    }

    // --- Reference: "POST /settings saves slack_webhook_url and redirects" ---

    public function test_saving_stores_the_slack_webhook_url(): void
    {
        Livewire::test(Settings::class)
            ->fillForm(['slack_webhook_url' => 'https://hooks.slack.com/test123'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('https://hooks.slack.com/test123', Setting::getValue('slack_webhook_url'));
    }

    public function test_saving_trims_surrounding_whitespace(): void
    {
        Livewire::test(Settings::class)
            // A URL pasted with a trailing newline is a URL that no longer
            // parses when the notifier POSTs to it.
            ->fillForm(['slack_webhook_url' => '  https://hooks.slack.com/spaced  '])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('https://hooks.slack.com/spaced', Setting::getValue('slack_webhook_url'));
    }

    public function test_saving_a_blank_url_clears_the_setting_and_disables_slack(): void
    {
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/old');

        Livewire::test(Settings::class)
            ->fillForm(['slack_webhook_url' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        // SlackNotifier::notify() early-returns on a falsy URL, so an empty
        // string is how notifications get turned off.
        $this->assertSame('', Setting::getValue('slack_webhook_url'));
    }

    // --- The test-notification action ---

    public function test_the_test_notification_posts_the_exact_message_to_the_saved_url(): void
    {
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/probe');
        Http::fake();

        Livewire::test(Settings::class)
            ->callAction('sendTestNotification');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://hooks.slack.com/probe'
                && $request->data() === ['text' => SlackNotifier::TEST_MESSAGE];
        });

        // Verbatim from settings.ts:29, em dash included.
        $this->assertSame(
            ':satellite: The Bridge test notification — connection OK.',
            SlackNotifier::TEST_MESSAGE,
        );

        Notification::assertNotified(
            Notification::make()->success()->title('Test notification sent to Slack.'),
        );
    }

    public function test_a_failing_test_notification_reports_through_the_danger_channel_not_the_success_one(): void
    {
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/broken');
        Http::fake(function (): never {
            throw new ConnectionException('slack is down');
        });

        Livewire::test(Settings::class)
            ->callAction('sendTestNotification');

        // The reference reports this failure through its SUCCESS flash
        // channel (settings.ts:31-33) — a defect the plan says to fix rather
        // than carry forward. An operator pressing a button whose only job is
        // to tell them whether the URL works must not be told it worked.
        Notification::assertNotified(
            Notification::make()->danger()->title('Failed to reach Slack webhook URL.'),
        );
    }

    public function test_a_slack_error_response_is_also_a_failure_not_a_success(): void
    {
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/gone');
        // A reachable host that rejects the payload — Slack answers 404 for a
        // revoked webhook, which is the single most likely real failure and
        // is NOT a connection error.
        Http::fake(fn () => Http::response('no_service', 404));

        Livewire::test(Settings::class)
            ->callAction('sendTestNotification');

        Notification::assertNotified(
            Notification::make()->danger()->title('Failed to reach Slack webhook URL.'),
        );
    }

    public function test_the_test_notification_is_not_offered_with_no_url_saved(): void
    {
        Livewire::test(Settings::class)
            ->assertActionHidden('sendTestNotification');
    }

    public function test_the_test_notification_is_offered_once_a_url_is_saved(): void
    {
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/present');

        // The positive case for the hidden-assertion above.
        Livewire::test(Settings::class)
            ->assertActionVisible('sendTestNotification');
    }
}
