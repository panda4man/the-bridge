<?php

namespace Tests\Unit\Services;

use App\Models\App;
use App\Models\Deployment;
use App\Models\Setting;
use App\Services\SlackNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ported from reference/tests/Unit/slackNotifier.test.ts (2 cases: does
 * nothing when no webhook URL configured, POSTs when configured). Extended
 * with cases for emoji/color per status, duration formatting, the 20-line
 * failure-only log tail, and the deployments/{id} context link — the
 * reference test file only asserts the fetch call happened (method: POST),
 * not the request body shape, so these are additive coverage for behaviour
 * the spec calls out explicitly.
 *
 * Deployment::app is a belongsTo relation here (not the reference's joined
 * app_name column — see app/Services/SlackNotifier.php), so several cases
 * deliberately re-fetch a bare Deployment (relation not eager-loaded) to
 * prove the notifier still resolves the app name correctly.
 */
class SlackNotifierTest extends TestCase
{
    use RefreshDatabase;

    private function singleSentRequest(): array
    {
        $recorded = Http::recorded();
        $this->assertCount(1, $recorded, 'Expected exactly one HTTP request to have been sent.');

        return $recorded->first();
    }

    public function test_does_nothing_when_no_slack_webhook_url_set(): void
    {
        Http::fake();
        $deployment = Deployment::factory()->for(App::factory())->create(['status' => 'success']);

        SlackNotifier::notify($deployment);

        Http::assertNothingSent();
    }

    public function test_posts_to_slack_when_url_is_configured(): void
    {
        Http::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/test');
        $app = App::factory()->create(['name' => 'MyApp']);
        $deployment = Deployment::factory()->for($app)->create([
            'status' => 'failed',
            'log' => "error line\n",
            'started_at' => '2026-01-01 00:00:00',
            'finished_at' => '2026-01-01 00:01:00',
        ]);

        SlackNotifier::notify($deployment);

        [$request] = $this->singleSentRequest();
        $this->assertSame('https://hooks.slack.com/test', $request->url());
        $this->assertSame('POST', $request->method());
    }

    public function test_uses_success_emoji_and_color_for_a_successful_deployment(): void
    {
        Http::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/test');
        $app = App::factory()->create(['name' => 'MyApp']);
        $deployment = Deployment::factory()->for($app)->create(['status' => 'success']);

        SlackNotifier::notify($deployment);

        [$request] = $this->singleSentRequest();
        $data = $request->data();
        $text = $data['attachments'][0]['blocks'][0]['text']['text'];

        $this->assertSame('#2eb886', $data['attachments'][0]['color']);
        $this->assertStringContainsString(':white_check_mark:', $text);
        $this->assertStringContainsString('success', $text);
        $this->assertStringContainsString('MyApp', $text);
    }

    public function test_uses_failure_emoji_and_color_for_a_failed_deployment(): void
    {
        Http::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/test');
        $app = App::factory()->create(['name' => 'MyApp']);
        $deployment = Deployment::factory()->for($app)->create(['status' => 'failed']);

        SlackNotifier::notify($deployment);

        [$request] = $this->singleSentRequest();
        $data = $request->data();
        $text = $data['attachments'][0]['blocks'][0]['text']['text'];

        $this->assertSame('#cc0000', $data['attachments'][0]['color']);
        $this->assertStringContainsString(':x:', $text);
        $this->assertStringContainsString('failed', $text);
    }

    public function test_formats_duration_under_a_minute_as_seconds(): void
    {
        Http::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/test');
        $app = App::factory()->create();
        $deployment = Deployment::factory()->for($app)->create([
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now()->addSeconds(45),
        ]);

        SlackNotifier::notify($deployment);

        [$request] = $this->singleSentRequest();
        $text = $request->data()['attachments'][0]['blocks'][0]['text']['text'];
        $this->assertStringContainsString('(45s)', $text);
    }

    public function test_formats_duration_over_a_minute_as_minutes_and_seconds(): void
    {
        Http::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/test');
        $app = App::factory()->create();
        $deployment = Deployment::factory()->for($app)->create([
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now()->addSeconds(125),
        ]);

        SlackNotifier::notify($deployment);

        [$request] = $this->singleSentRequest();
        $text = $request->data()['attachments'][0]['blocks'][0]['text']['text'];
        $this->assertStringContainsString('(2m 5s)', $text);
    }

    /**
     * QC: the earlier 45s/125s cases straddle the >=60 boundary widely
     * enough that mutating it to `>= 61` still passed the full suite (both
     * 45 and 125 land the same side of 60 either way). 59/60/61 pin the
     * boundary itself.
     */
    public function test_duration_boundary_59_seconds_formats_as_seconds(): void
    {
        Http::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/test');
        $app = App::factory()->create();
        $deployment = Deployment::factory()->for($app)->create([
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now()->addSeconds(59),
        ]);

        SlackNotifier::notify($deployment);

        [$request] = $this->singleSentRequest();
        $text = $request->data()['attachments'][0]['blocks'][0]['text']['text'];
        $this->assertStringContainsString('(59s)', $text);
    }

    public function test_duration_boundary_60_seconds_formats_as_minutes_and_seconds(): void
    {
        Http::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/test');
        $app = App::factory()->create();
        $deployment = Deployment::factory()->for($app)->create([
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now()->addSeconds(60),
        ]);

        SlackNotifier::notify($deployment);

        [$request] = $this->singleSentRequest();
        $text = $request->data()['attachments'][0]['blocks'][0]['text']['text'];
        $this->assertStringContainsString('(1m 0s)', $text);
    }

    public function test_duration_boundary_61_seconds_formats_as_minutes_and_seconds(): void
    {
        Http::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/test');
        $app = App::factory()->create();
        $deployment = Deployment::factory()->for($app)->create([
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now()->addSeconds(61),
        ]);

        SlackNotifier::notify($deployment);

        [$request] = $this->singleSentRequest();
        $text = $request->data()['attachments'][0]['blocks'][0]['text']['text'];
        $this->assertStringContainsString('(1m 1s)', $text);
    }

    public function test_omits_duration_when_timestamps_are_missing(): void
    {
        Http::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/test');
        $app = App::factory()->create();
        $deployment = Deployment::factory()->for($app)->create([
            'status' => 'success',
            'started_at' => null,
            'finished_at' => null,
        ]);

        SlackNotifier::notify($deployment);

        [$request] = $this->singleSentRequest();
        $text = $request->data()['attachments'][0]['blocks'][0]['text']['text'];
        $this->assertStringNotContainsString('(', $text);
    }

    public function test_includes_only_last_20_log_lines_on_failure(): void
    {
        Http::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/test');
        $app = App::factory()->create();
        $lines = collect(range(1, 30))->map(fn ($n) => "line {$n}")->implode("\n");
        $deployment = Deployment::factory()->for($app)->create(['status' => 'failed', 'log' => $lines]);

        SlackNotifier::notify($deployment);

        [$request] = $this->singleSentRequest();
        $blocks = $request->data()['attachments'][0]['blocks'];
        $logBlock = $blocks[1]['text']['text'];

        $this->assertStringContainsString('line 11', $logBlock);
        $this->assertStringContainsString('line 30', $logBlock);
        $this->assertStringNotContainsString('line 10'."\n", $logBlock);
    }

    public function test_does_not_include_a_log_block_on_success(): void
    {
        Http::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/test');
        $app = App::factory()->create();
        $deployment = Deployment::factory()->for($app)->create(['status' => 'success', 'log' => "all good\n"]);

        SlackNotifier::notify($deployment);

        [$request] = $this->singleSentRequest();
        $blocks = $request->data()['attachments'][0]['blocks'];

        // Only the summary section + context link, no log block in between.
        $this->assertCount(2, $blocks);
        $this->assertSame('context', $blocks[1]['type']);
    }

    public function test_context_block_links_to_the_deployment(): void
    {
        Http::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/test');
        $app = App::factory()->create();
        $deployment = Deployment::factory()->for($app)->create(['status' => 'success']);

        SlackNotifier::notify($deployment);

        [$request] = $this->singleSentRequest();
        $blocks = $request->data()['attachments'][0]['blocks'];
        $linkText = end($blocks)['elements'][0]['text'];

        $this->assertStringContainsString("/deployments/{$deployment->id}|View deployment log", $linkText);
    }

    public function test_uses_the_related_apps_name_via_the_belongs_to_relation(): void
    {
        Http::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/test');
        $app = App::factory()->create(['name' => 'Specific App Name']);
        $created = Deployment::factory()->for($app)->create(['status' => 'success']);
        $bareDeployment = Deployment::find($created->id); // fresh model, relation not eager-loaded

        SlackNotifier::notify($bareDeployment);

        [$request] = $this->singleSentRequest();
        $text = $request->data()['attachments'][0]['blocks'][0]['text']['text'];
        $this->assertStringContainsString('Specific App Name', $text);
    }

    public function test_falls_back_to_the_literal_app_when_no_related_app_exists(): void
    {
        Http::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/test');
        $deployment = Deployment::factory()->make(['app_id' => 999999, 'status' => 'success']);
        $deployment->id = 42; // synthetic — unsaved, so the context link's id would otherwise be null

        SlackNotifier::notify($deployment);

        [$request] = $this->singleSentRequest();
        $text = $request->data()['attachments'][0]['blocks'][0]['text']['text'];
        $this->assertStringContainsString('*App*', $text);
    }

    public function test_falls_back_to_the_literal_base_url_when_app_url_is_unconfigured(): void
    {
        Http::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/test');
        $app = App::factory()->create();
        $deployment = Deployment::factory()->for($app)->create(['status' => 'success']);

        config(['app.url' => '']);
        SlackNotifier::notify($deployment);

        [$request] = $this->singleSentRequest();
        $blocks = $request->data()['attachments'][0]['blocks'];
        $linkText = end($blocks)['elements'][0]['text'];

        $this->assertStringContainsString('http://localhost:3000/deployments/', $linkText);
    }

    /**
     * QC: the earlier "only last 20 log lines" case pins the LINE COUNT but
     * not the trim() around the joined tail — a log with trailing blank
     * lines would leave the block ending in extra "\n"s before the closing
     * backticks, and nothing caught that.
     */
    public function test_trims_whitespace_from_the_log_tail(): void
    {
        Http::fake();
        Setting::setValue('slack_webhook_url', 'https://hooks.slack.com/test');
        $app = App::factory()->create();
        $deployment = Deployment::factory()->for($app)->create([
            'status' => 'failed',
            'log' => "line1\nline2\n\n",
        ]);

        SlackNotifier::notify($deployment);

        [$request] = $this->singleSentRequest();
        $blocks = $request->data()['attachments'][0]['blocks'];
        $logBlock = $blocks[1]['text']['text'];

        $this->assertSame("```line1\nline2```", $logBlock);
    }
}
