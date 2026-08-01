<?php

namespace App\Filament\Resources\Deployments\Pages;

use App\Filament\Resources\Deployments\DeploymentResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No header actions — there is no way to create a deployment from here. They
 * come from the Deploy action, a rollback, a branch change, or a webhook.
 */
class ListDeployments extends ListRecords
{
    protected static string $resource = DeploymentResource::class;
}
