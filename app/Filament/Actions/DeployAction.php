<?php

namespace App\Filament\Actions;

use App\Enums\DeploymentStatus;
use App\Filament\Resources\Deployments\DeploymentResource;
use App\Jobs\DeployApp;
use App\Models\App;
use App\Models\Deployment;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Queue a deploy of an app's tracked branch. Ported from
 * reference/src/routes/apps.ts:178-184.
 *
 * Creates the `pending` row FIRST and dispatches with its id — the job takes
 * only an id and re-reads both rows itself, so the row must exist before the
 * worker can pick the job up. Then redirects to that deployment, which is
 * where the live log lives (Phase 6).
 *
 * `queue()` is the single place a deploy is enqueued from anywhere in the
 * app — the panel (this action), the GitHub webhook
 * (App\Http\Controllers\WebhookController), the token-authenticated API
 * (App\Http\Controllers\Api\AppsController::deploy), and the parity-path
 * deploy/rollback endpoints (App\Http\Controllers\ParityController) all call
 * it rather than creating the row and dispatching by hand.
 */
final class DeployAction
{
    public static function make(string $name = 'deploy'): Action
    {
        return Action::make($name)
            ->label('Deploy')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Deploy this app?')
            ->modalDescription(fn (App $record): string => "Deploys branch \"{$record->branch}\" from {$record->repo_url}.")
            ->modalSubmitActionLabel('Deploy')
            // Return type is mixed, not RedirectResponse: inside Livewire,
            // redirect() resolves to Livewire's own Redirector, not the
            // framework's response object.
            ->action(static fn (App $record): mixed => redirect(
                DeploymentResource::getUrl('view', ['record' => self::queue($record)]),
            ));
    }

    /**
     * $rollbackSha, when given, is carried onto the new deployment as
     * `rollback_sha` — this is what turns a plain deploy into a rollback
     * (App\Jobs\DeployApp checks out that SHA instead of the tracked
     * branch's HEAD). Only set when non-null so a plain deploy's row has no
     * `rollback_sha` key touched at all, matching Deployment::create()'s
     * previous (pre-widening) behaviour exactly.
     *
     * Added so App\Filament\Actions\RollbackAction could delegate here
     * instead of duplicating "create the pending row, then dispatch its id"
     * a second time — see that class for the guards it still runs before
     * calling this.
     */
    public static function queue(App $app, ?string $rollbackSha = null): Deployment
    {
        $attributes = [
            'app_id' => $app->id,
            'status' => DeploymentStatus::Pending,
        ];

        if ($rollbackSha !== null) {
            $attributes['rollback_sha'] = $rollbackSha;
        }

        $deployment = Deployment::create($attributes);

        DeployApp::dispatch($deployment->id);

        return $deployment;
    }
}
