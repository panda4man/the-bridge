<?php

namespace Tests\Feature\Filament;

use App\Enums\DeploymentStatus;
use App\Filament\Resources\Deployments\Pages\ViewDeployment;
use App\Livewire\DeploymentLog;
use App\Models\App;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportEvents\SupportEvents;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The polling deploy log viewer.
 *
 * Replaces reference/tests/Feature/sseStream.test.ts, whose two cases
 * ("streams log chunks as they are appended" and "closes the stream once the
 * deployment is terminal") are the behaviours this component has to keep;
 * everything else here is specific to how polling differs from SSE.
 */
class DeploymentLogTest extends TestCase
{
    use RefreshDatabase;

    private App $app_;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->app_ = App::factory()->create([
            'name' => 'Logger',
            'path' => '/repos/logger',
        ]);
    }

    private function makeDeployment(array $overrides = []): Deployment
    {
        return Deployment::create(array_merge([
            'app_id' => $this->app_->id,
            'status' => DeploymentStatus::Running,
        ], $overrides));
    }

    // --- Mount ---

    public function test_it_renders_the_log_that_already_exists_and_starts_at_its_end(): void
    {
        $deployment = $this->makeDeployment(['log' => "already built\n"]);

        Livewire::test(DeploymentLog::class, ['record' => $deployment])
            ->assertSeeText('already built')
            // Starting anywhere else re-sends output that is already on screen.
            ->assertSet('offset', 14)
            ->assertSet('status', 'running')
            ->assertSet('done', false);
    }

    public function test_a_deployment_with_no_output_yet_shows_the_placeholder(): void
    {
        $deployment = $this->makeDeployment(['log' => null]);

        Livewire::test(DeploymentLog::class, ['record' => $deployment])
            // The reference's own empty-state text (show.ejs:27).
            ->assertSeeText('Waiting for output...')
            ->assertSet('offset', 0);
    }

    public function test_the_log_is_rendered_as_text_not_html(): void
    {
        $deployment = $this->makeDeployment([
            'log' => '<script>alert(1)</script>',
        ]);

        // Build output is untrusted: it is whatever a repo's own build printed.
        Livewire::test(DeploymentLog::class, ['record' => $deployment])
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertSeeText('<script>alert(1)</script>');
    }

    // --- Reference: "streams log chunks as they are appended" ---

    public function test_a_poll_dispatches_only_the_bytes_appended_since_the_last_one(): void
    {
        $deployment = $this->makeDeployment(['log' => "first\n"]);

        $component = Livewire::test(DeploymentLog::class, ['record' => $deployment]);

        $deployment->appendLog("second\n");

        $component->call('poll')
            // Only the new chunk — the browser already has "first\n" in the
            // DOM and appends to it.
            ->assertDispatched('deployment-log-appended', text: "second\n")
            ->assertSet('offset', 13);
    }

    public function test_the_offset_advances_to_the_whole_log_length_not_the_chunk_length(): void
    {
        $deployment = $this->makeDeployment(['log' => 'aaaa']);

        $component = Livewire::test(DeploymentLog::class, ['record' => $deployment]);

        $deployment->appendLog('bb');

        // Same contract as the API's X-Log-Offset header: the offset describes
        // the whole log, because it is what the NEXT poll slices from. An
        // offset of 2 (this chunk's length) would re-send "aa" every second
        // and never reach the end of a growing log.
        $component->call('poll')->assertSet('offset', 6);
    }

    public function test_a_poll_with_no_new_output_dispatches_nothing(): void
    {
        $deployment = $this->makeDeployment(['log' => "quiet\n"]);

        Livewire::test(DeploymentLog::class, ['record' => $deployment])
            ->call('poll')
            // An empty dispatch every second would still cost a browser event
            // and an auto-scroll on every idle tick of a stalled build.
            ->assertNotDispatched('deployment-log-appended');
    }

    public function test_offsets_are_bytes_so_multibyte_output_does_not_split_a_character(): void
    {
        // A single 3-byte character — npm and docker output is full of these.
        $deployment = $this->makeDeployment(['log' => '→']);

        $component = Livewire::test(DeploymentLog::class, ['record' => $deployment])
            // 3, not 1: docs/porting-notes.md fixes bytes as this port's log
            // offset unit, matching strlen()/substr() and the API's own
            // log_length.
            ->assertSet('offset', 3);

        $deployment->appendLog('x');

        // With a character offset of 1 this chunk would be the last two bytes
        // of the arrow followed by 'x' — mojibake in the log box.
        $component->call('poll')
            ->assertDispatched('deployment-log-appended', text: 'x')
            ->assertSet('offset', 4);
    }

    public function test_several_polls_in_a_row_never_repeat_or_drop_output(): void
    {
        $deployment = $this->makeDeployment(['log' => null]);

        $component = Livewire::test(DeploymentLog::class, ['record' => $deployment]);

        foreach (['one', 'two', 'three'] as $chunk) {
            $deployment->appendLog($chunk);

            $component->call('poll')->assertDispatched('deployment-log-appended', text: $chunk);
        }

        $this->assertSame(11, $component->get('offset'));
    }

    // --- Reference: "closes the stream once the deployment is terminal" ---

    public function test_it_polls_while_the_deployment_is_unfinished(): void
    {
        $deployment = $this->makeDeployment(['status' => DeploymentStatus::Pending]);

        Livewire::test(DeploymentLog::class, ['record' => $deployment])
            ->assertSeeHtml('wire:poll.1s="poll"');
    }

    public function test_it_never_starts_polling_a_deployment_that_is_already_finished(): void
    {
        $deployment = $this->makeDeployment([
            'status' => DeploymentStatus::Success,
            'log' => "done\n",
        ]);

        Livewire::test(DeploymentLog::class, ['record' => $deployment])
            ->assertSet('done', true)
            // Opening an old deployment must not start a request every second
            // against a row nothing will ever write to again.
            ->assertDontSeeHtml('wire:poll');
    }

    public function test_the_poll_attribute_is_dropped_once_the_deployment_finishes(): void
    {
        $deployment = $this->makeDeployment();

        $component = Livewire::test(DeploymentLog::class, ['record' => $deployment])
            ->assertSeeHtml('wire:poll.1s="poll"');

        $deployment->forceFill(['status' => DeploymentStatus::Success])->save();

        // Livewire's poll directive pauses when the attribute leaves the
        // element (its wire-poll.js `theDirectiveIsOffTheElement`), so this IS
        // the stop condition, not a cosmetic detail.
        $component->call('poll')
            ->assertSet('done', true)
            ->assertDontSeeHtml('wire:poll');
    }

    public function test_the_last_chunk_is_delivered_by_the_same_poll_that_stops_polling(): void
    {
        $deployment = $this->makeDeployment();

        $component = Livewire::test(DeploymentLog::class, ['record' => $deployment]);

        // What the worker actually does: write the final output, then mark the
        // deployment finished.
        $deployment->appendLog("Deploy complete\n");
        $deployment->forceFill(['status' => DeploymentStatus::Success])->save();

        // Stopping before reading would truncate the log at the second-to-last
        // poll, permanently — nothing polls again to pick the rest up.
        $component->call('poll')
            ->assertDispatched('deployment-log-appended', text: "Deploy complete\n")
            ->assertSet('done', true);
    }

    public function test_a_failed_deployment_also_stops_the_stream(): void
    {
        $deployment = $this->makeDeployment();

        $component = Livewire::test(DeploymentLog::class, ['record' => $deployment]);

        $deployment->forceFill(['status' => DeploymentStatus::Failed])->save();

        // `failed` is terminal too — the reference's TERMINAL list is both
        // statuses, not just success.
        $component->call('poll')->assertSet('done', true);
    }

    public function test_a_deployment_that_disappears_ends_the_stream_instead_of_erroring(): void
    {
        $deployment = $this->makeDeployment();

        $component = Livewire::test(DeploymentLog::class, ['record' => $deployment]);

        // Deleting the app cascades to its deployments; the page can still be
        // open when that happens. The reference stream's own
        // `if (!dep) { clearInterval(...); res.end(); }`.
        $this->app_->delete();

        $component->call('poll')
            ->assertSet('done', true)
            ->assertNotDispatched('deployment-log-appended');
    }

    // --- Keeping the rest of the page honest ---

    public function test_a_status_change_is_announced_to_the_page(): void
    {
        $deployment = $this->makeDeployment(['status' => DeploymentStatus::Pending]);

        $component = Livewire::test(DeploymentLog::class, ['record' => $deployment]);

        $deployment->forceFill(['status' => DeploymentStatus::Running])->save();

        // Not only on completion: the infolist's badge is as wrong showing
        // `pending` for a running deploy as it is showing `running` for a
        // finished one.
        $component->call('poll')
            ->assertSet('status', 'running')
            ->assertDispatched('deployment-status-changed');
    }

    public function test_an_unchanged_status_is_not_announced(): void
    {
        $deployment = $this->makeDeployment();

        $component = Livewire::test(DeploymentLog::class, ['record' => $deployment]);

        $deployment->appendLog('still going');

        // Otherwise every one-second poll of a long build re-renders the whole
        // page around it.
        $component->call('poll')->assertNotDispatched('deployment-status-changed');
    }

    public function test_the_deployment_page_listens_for_that_announcement(): void
    {
        $deployment = $this->makeDeployment();

        $page = Livewire::test(
            ViewDeployment::class,
            ['record' => $deployment->getRouteKey()],
        );

        // The other half of the contract above. Renaming the event on either
        // side leaves a live log under a permanently stale status badge, with
        // nothing failing.
        $this->assertContains(
            'deployment-status-changed',
            SupportEvents::getListenerEventNames($page->instance()),
        );
    }

    public function test_the_deployment_page_embeds_the_log_component(): void
    {
        $deployment = $this->makeDeployment(['log' => "from the page\n"]);

        // The infolist used to render `log` as a static TextEntry carrying the
        // same .lcars-log class, which looks identical on a finished
        // deployment and never moves on a running one. So seeing the log text
        // and the class proves nothing on its own: the poll attribute is what
        // only the Livewire component can produce.
        Livewire::test(
            ViewDeployment::class,
            ['record' => $deployment->getRouteKey()],
        )
            ->assertSeeText('from the page')
            ->assertSeeHtml('lcars-log')
            ->assertSeeHtml('wire:poll.1s="poll"');
    }
}
