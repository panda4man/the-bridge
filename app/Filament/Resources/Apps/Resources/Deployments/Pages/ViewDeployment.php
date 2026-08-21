<?php

namespace App\Filament\Resources\Apps\Resources\Deployments\Pages;

use App\Filament\Actions\ResetDeploymentAction;
use App\Filament\Actions\RollbackAction;
use App\Filament\Resources\Apps\Resources\Deployments\DeploymentResource;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

/**
 * Mirrors the global resource's ViewDeployment page (same header actions,
 * same log-refresh wiring) — see that class's docblock for why. RollbackAction
 * redirects to the GLOBAL resource's view page after queuing a new deployment
 * (it hardcodes that URL, shared with the global DeploymentsTable's own row
 * action); left as-is rather than special-cased here, since the redirect
 * target still resolves to a valid, viewable record either way.
 */
class ViewDeployment extends ViewRecord
{
    protected static string $resource = DeploymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RollbackAction::make(),
            ResetDeploymentAction::make(),
        ];
    }

    #[On('deployment-status-changed')]
    public function refreshDeployment(): void
    {
        $this->getRecord()->refresh();
    }
}
