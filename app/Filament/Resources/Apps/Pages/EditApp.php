<?php

namespace App\Filament\Resources\Apps\Pages;

use App\Enums\DeploymentStatus;
use App\Filament\Actions\DeployAction;
use App\Filament\Actions\EditEnvFileAction;
use App\Filament\Actions\RegenerateWebhookSecretAction;
use App\Filament\Resources\Apps\AppResource;
use App\Filament\Resources\Apps\Schemas\AppForm;
use App\Filament\Resources\Deployments\DeploymentResource;
use App\Jobs\DeployApp;
use App\Models\Deployment;
use App\Services\AppProvisioner;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Updating an app has a side effect: changing `branch` immediately queues a
 * deploy of the new branch and sends the operator to that deployment instead
 * of back to the app. Ported from reference/src/routes/apps.ts:110-127.
 *
 * Deleting an app has a bigger one — see the DeleteAction below.
 */
class EditApp extends EditRecord
{
    protected static string $resource = AppResource::class;

    /**
     * Id of the deployment queued by a branch change, or null when the branch
     * did not change. Drives BOTH the notification wording and the redirect,
     * so it is computed once in handleRecordUpdate() and read twice.
     */
    private ?int $branchChangeDeploymentId = null;

    protected function getHeaderActions(): array
    {
        return [
            DeployAction::make(),
            RegenerateWebhookSecretAction::make(),
            EditEnvFileAction::make(),
            ViewAction::make(),
            // The record's containers and its directory go before the row
            // does. ->before() runs while the record still exists; doing this
            // after would leave a deleted app with its stack still running
            // and its checkout still on disk, unreachable from the UI.
            DeleteAction::make()
                ->before(fn (): mixed => app(AppProvisioner::class)->destroy($this->getRecord()->path))
                ->successNotificationTitle('App removed.'),
        ];
    }

    /**
     * The `deploy_steps` column holds JSON; the form edits plain text. This
     * is the load half of that conversion — see AppForm::deployStepsText()
     * for why a repo `bridge.yml` wins over the column here.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['deploy_steps_text'] = AppForm::deployStepsText($this->getRecord());

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $previousBranch = $record->branch;

        $data['deploy_steps'] = AppForm::deployStepsJson((string) ($data['deploy_steps_text'] ?? ''));
        unset($data['deploy_steps_text']);

        // No `health_url: health_url || null` coercion here, unlike
        // reference/src/routes/apps.ts:118. Filament already dehydrates a
        // blank TextInput to NULL, so the coercion was dead code — verified
        // by mutation: deleting it left the whole suite green, and a direct
        // probe confirmed the column is NULL either way. The behaviour still
        // matters (an empty string is a URL the health poller would dutifully
        // poll) and is pinned by
        // test_clearing_the_health_url_nulls_the_column_rather_than_storing_an_empty_string,
        // which asserts the OUTCOME rather than this mechanism.
        $record->update($data);

        if ($record->branch !== $previousBranch) {
            $deployment = Deployment::create([
                'app_id' => $record->id,
                'status' => DeploymentStatus::Pending,
            ]);

            DeployApp::dispatch($deployment->id);

            $this->branchChangeDeploymentId = $deployment->id;
        }

        return $record;
    }

    protected function getSavedNotification(): ?Notification
    {
        if ($this->branchChangeDeploymentId === null) {
            return Notification::make()->success()->title('App updated.');
        }

        return Notification::make()
            ->success()
            ->title('App updated. Deploying branch "'.$this->getRecord()->branch.'"...');
    }

    /**
     * A branch change redirects to the DEPLOYMENT it just queued; anything
     * else goes back to the app. The reference redirects to `/apps/:id` on a
     * plain update, not to the list.
     */
    protected function getRedirectUrl(): string
    {
        if ($this->branchChangeDeploymentId !== null) {
            return DeploymentResource::getUrl('view', ['record' => $this->branchChangeDeploymentId]);
        }

        return AppResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
