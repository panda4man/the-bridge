<?php

namespace App\Filament\Resources\Deployments\Pages;

use App\Filament\Actions\ResetDeploymentAction;
use App\Filament\Actions\RollbackAction;
use App\Filament\Resources\Deployments\DeploymentResource;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

class ViewDeployment extends ViewRecord
{
    protected static string $resource = DeploymentResource::class;

    protected function getHeaderActions(): array
    {
        // No EditAction — the resource is read-only. Both actions hide
        // themselves for statuses they do not apply to; see their docblocks.
        return [
            RollbackAction::make(),
            ResetDeploymentAction::make(),
        ];
    }

    /**
     * Re-render when the log viewer sees the deployment's status change.
     *
     * Everything on this page except the log is a snapshot of the status at
     * page load: the infolist's status badge, duration and finished_at, and
     * which of the two header actions is offered. Without this, a deploy that
     * finishes while you watch leaves a live log under a stale `running` badge
     * and a Reset button for a deployment that no longer needs resetting —
     * exactly what the reference's `x-show="!done"` avoided.
     *
     * `$record` is re-queried on every Livewire request (Filament declares it
     * as a public model property, which Livewire's model synth rehydrates from
     * the database), so the work here is the re-render, not the refresh; the
     * refresh is belt-and-braces against that ever changing.
     */
    #[On('deployment-status-changed')]
    public function refreshDeployment(): void
    {
        $this->getRecord()->refresh();
    }
}
