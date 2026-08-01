<?php

namespace App\Livewire;

use App\Models\Deployment;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The live deploy log on the deployment view page.
 *
 * Replaces the reference's SSE stream (reference/src/routes/deployments.ts
 * `GET /deployments/:id/stream` plus the Alpine `EventSource` wiring in
 * reference/src/views/deployments/show.ejs) with polling, per the port plan.
 *
 * Three decisions worth knowing before changing anything here:
 *
 * 1. **It reads the database, it does not call `GET /api/deployments/{id}/log`.**
 *    That route is behind `api.token`; a panel session has no bearer token to
 *    send, so a browser fetch to it would be a 401. What actually keeps the two
 *    consistent is that both go through `Deployment::logSlice()`/`logLength()`,
 *    so "the offset" means the same bytes on both paths.
 *
 * 2. **Only the offset lives in component state — never the log.** Every public
 *    property is serialised into the Livewire snapshot and sent BACK to the
 *    server on each poll. Holding the log would mean shipping the whole thing
 *    over the wire twice a second on a build that prints megabytes. New output
 *    is dispatched as a browser event and appended to a `wire:ignore`d `<pre>`
 *    by Alpine, which is also what stops the morph from wiping it.
 *
 * 3. **Polling stops by removing the `wire:poll` attribute**, not by any JS API.
 *    Livewire's poll directive pauses when the attribute is no longer on the
 *    element (`theDirectiveIsOffTheElement` in its `wire-poll.js`), so the view
 *    renders `wire:poll.1s` only while `$done` is false.
 */
class DeploymentLog extends Component
{
    /**
     * The record is carried as an id rather than as a model property.
     *
     * Filament's Livewire schema component passes `record` as a mount
     * argument (Filament\Schemas\Components\Livewire::getComponentProperties()),
     * but a poll must read the log the WORKER just wrote, so every request
     * re-queries rather than trusting anything hydrated from the snapshot.
     */
    public int $deploymentId;

    /** Byte offset into the log; everything before it is already in the DOM. */
    public int $offset = 0;

    public string $status = '';

    public bool $done = false;

    /**
     * Rendered into the `<pre>` on the first render only.
     *
     * Not public, so it never enters the snapshot. Subsequent renders leave it
     * empty, which is harmless because the `<pre>` is `wire:ignore`d and the
     * browser owns its contents from mount onwards.
     */
    protected string $initialLog = '';

    public function mount(Deployment $record): void
    {
        $this->deploymentId = $record->getKey();
        $this->initialLog = $record->log ?? '';
        $this->offset = $record->logLength();
        $this->status = $record->status->value;
        $this->done = $record->status->isTerminal();
    }

    /**
     * One poll: append whatever the worker has written since the last one.
     *
     * A deployment that has disappeared (its app was deleted — the FK cascades)
     * ends the stream rather than erroring, matching the reference stream's
     * `if (!dep) { clearInterval(interval); res.end(); return; }`.
     */
    public function poll(): void
    {
        $deployment = Deployment::query()->find($this->deploymentId);

        if (! $deployment) {
            $this->done = true;

            return;
        }

        $chunk = $deployment->logSlice($this->offset);

        if ($chunk !== '') {
            // The full log length, not $offset + strlen($chunk). Same contract
            // as the API's X-Log-Offset header: it is what the NEXT poll asks
            // from, so it has to describe the whole log, not this slice.
            $this->offset = $deployment->logLength();

            $this->dispatch('deployment-log-appended', text: $chunk);
        }

        $this->done = $deployment->status->isTerminal();

        if ($deployment->status->value !== $this->status) {
            $this->status = $deployment->status->value;

            // The page AROUND this component — the infolist's status, duration
            // and finished_at entries, and the Reset header action's visibility
            // — was rendered against the old status and is now stale. This is
            // the polling equivalent of the reference view's own `status`/`done`
            // bindings updating off the stream (show.ejs:2,13,16), and it is why
            // this component does not render a status badge of its own: there
            // would then be two on the page, disagreeing for up to a second.
            $this->dispatch('deployment-status-changed');
        }
    }

    public function render(): View
    {
        return view('livewire.deployment-log', [
            'initialLog' => $this->initialLog,
        ]);
    }
}
