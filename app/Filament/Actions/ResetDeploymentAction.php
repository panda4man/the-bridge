<?php

namespace App\Filament\Actions;

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Models\App;
use App\Models\Deployment;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Manually fail a deployment the worker has stopped reporting on, and put its
 * app back to `failed` with it.
 *
 * Ported from reference/src/routes/deployments.ts:56-70 (`POST
 * /deployments/:id/reset`), which the view offers only while the deployment is
 * `running` or `pending` (show.ejs:15-22).
 *
 * **The reference's unconditional success flash is a listed defect and is
 * fixed here.** Its route applies the reset only when the status is resettable
 * but flashes `Deployment reset to failed.` either way, so clicking Reset on a
 * deployment that finished a moment ago reports a state change that did not
 * happen. That window is real rather than theoretical: the page polls a live
 * deploy, so the worker can reach a terminal status between the render that
 * drew this button and the click that submits it.
 *
 * What closes it here is `visible()`, and only `visible()` — deliberately,
 * after checking rather than assuming. Filament evaluates it against a
 * freshly-loaded record at BOTH `mountAction()` and `callMountedAction()`
 * (`Filament\Actions\Concerns\InteractsWithActions:158,244` — a hidden action
 * is disabled, and a disabled action unmounts without running), so a stale
 * click reaches no code of ours at all. An in-action re-read of the status was
 * written first and then removed: it could not fire, and this port does not
 * keep defensive code that provably never runs (see Phase 4's two removals in
 * docs/porting-notes.md). The reachable behaviour — a finished deployment is
 * neither reset nor reported as reset — is pinned on the mechanism that
 * actually implements it, in ResetDeploymentTest.
 *
 * The cost of that is a stale click doing nothing silently. It is small: the
 * log viewer announces the status change (App\Livewire\DeploymentLog) and the
 * page re-renders without this button within a second of the deploy ending.
 */
final class ResetDeploymentAction
{
    public static function make(string $name = 'reset'): Action
    {
        return Action::make($name)
            ->label('Reset')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            // The reference's own confirm() text, verbatim (show.ejs:18).
            ->modalHeading('Mark this deployment as failed?')
            ->modalDescription('The deploy process is not stopped — this only unsticks the record so the app can be deployed again.')
            ->modalSubmitActionLabel('Reset')
            ->visible(static fn (Deployment $record): bool => $record->status->isResettable())
            ->action(static function (Deployment $record): void {
                $record->forceFill([
                    'status' => DeploymentStatus::Failed,
                    'finished_at' => now(),
                ])->save();

                // Scoped to this deployment's own app, and by a query rather
                // than through the loaded relation, so a stale in-memory App
                // cannot write an old status back over anything else.
                App::query()
                    ->whereKey($record->app_id)
                    ->update(['status' => AppStatus::Failed]);

                Notification::make()
                    ->success()
                    // The reference's flash message, verbatim.
                    ->title('Deployment reset to failed.')
                    ->send();
            });
    }
}
