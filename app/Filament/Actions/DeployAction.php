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

    public static function queue(App $app): Deployment
    {
        $deployment = Deployment::create([
            'app_id' => $app->id,
            'status' => DeploymentStatus::Pending,
        ]);

        DeployApp::dispatch($deployment->id);

        return $deployment;
    }
}
