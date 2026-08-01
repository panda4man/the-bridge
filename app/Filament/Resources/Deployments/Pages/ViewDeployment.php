<?php

namespace App\Filament\Resources\Deployments\Pages;

use App\Filament\Actions\RollbackAction;
use App\Filament\Resources\Deployments\DeploymentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDeployment extends ViewRecord
{
    protected static string $resource = DeploymentResource::class;

    protected function getHeaderActions(): array
    {
        // No EditAction — the resource is read-only. RollbackAction hides
        // itself for anything that is not a successful deployment with a
        // commit SHA; see its docblock.
        return [
            RollbackAction::make(),
        ];
    }
}
