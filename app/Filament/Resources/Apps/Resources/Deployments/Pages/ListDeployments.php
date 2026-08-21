<?php

namespace App\Filament\Resources\Apps\Resources\Deployments\Pages;

use App\Filament\Resources\Apps\Resources\Deployments\DeploymentResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No header actions — same as the global resource's ListDeployments: there
 * is no way to create a deployment from here, only the Deploy action, a
 * rollback, a branch change, or a webhook.
 */
class ListDeployments extends ListRecords
{
    protected static string $resource = DeploymentResource::class;
}
