<?php

namespace App\Filament\Actions;

use App\Enums\DeploymentStatus;
use App\Filament\Resources\Deployments\DeploymentResource;
use App\Models\Deployment;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Redeploy an app at an earlier deployment's commit. Ported from
 * reference/src/routes/apps.ts:195-211.
 *
 * Three guards in the reference route, and how each lands here:
 *
 * 1. `!source || source.app_id !== app.id` → 404. Structurally impossible in
 *    the panel: the action is bound to a Deployment record and reads
 *    `$record->app_id` off it, so there is no second app id to disagree with.
 *    Phase 5's `POST /apps/:id/rollback` DOES take both ids and must keep the
 *    check — it is a real authorisation boundary there, not here.
 * 2. `!source.commit_sha` → 400. Enforced here by `visible()` alone, which is
 *    not merely presentation: Filament evaluates it against a freshly-loaded
 *    record at BOTH `mountAction()` and `callMountedAction()`
 *    (`Filament\Actions\Concerns\InteractsWithActions:158` and `:244`), so a
 *    stale page that submits against a row whose SHA has since been cleared
 *    finds the action hidden, therefore disabled, and it unmounts without
 *    running. This class carried a second in-action `! $record->commit_sha`
 *    refusal until Phase 8; it was deleted because it could not fire —
 *    confirmed the same way Phase 6 confirmed it for `ResetDeploymentAction`,
 *    by writing the test for the notification and watching it fail with "A
 *    notification was not sent". Phase 5's `POST /apps/:id/rollback` is a
 *    different matter and keeps its own 400: it has no Filament in front of
 *    it.
 * 3. Only successful deployments are offered. The reference does not enforce
 *    this in the route — its view only renders the button on successful rows
 *    (`views/deployments/show.ejs`) — so this stays a visibility rule to
 *    match, not a hard refusal.
 */
final class RollbackAction
{
    public static function make(string $name = 'rollback'): Action
    {
        return Action::make($name)
            ->label('Rollback to this commit')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Roll back to this commit?')
            ->modalDescription(fn (Deployment $record): string => 'Redeploys '
                .($record->commit_sha ? substr($record->commit_sha, 0, 7) : '?')
                .' as a new deployment. The current checkout is replaced.')
            ->modalSubmitActionLabel('Roll back')
            ->visible(static fn (Deployment $record): bool => $record->status === DeploymentStatus::Success
                && (bool) $record->commit_sha)
            // Return type is mixed, not RedirectResponse: inside Livewire,
            // redirect() resolves to Livewire's own Redirector.
            ->action(static function (Deployment $record): mixed {
                // Delegates row-creation + dispatch to DeployAction::queue()
                // — the single shared enqueue path (see that class's
                // docblock) — rather than duplicating it here. $record->app
                // is the SOURCE deployment's own app relation, which is what
                // makes guard #1 in this class's docblock ("no second app id
                // to disagree with") true: there is structurally only one
                // app in play.
                $rollback = DeployAction::queue($record->app, $record->commit_sha);

                return redirect(DeploymentResource::getUrl('view', ['record' => $rollback]));
            });
    }
}
