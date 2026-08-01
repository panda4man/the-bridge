<?php

namespace App\Filament\Resources\Apps\Pages;

use App\Filament\Actions\DeployAction;
use App\Filament\Actions\EditEnvFileAction;
use App\Filament\Resources\Apps\AppResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewApp extends ViewRecord
{
    protected static string $resource = AppResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeployAction::make(),
            EditEnvFileAction::make(),
            EditAction::make(),
        ];
    }
}
