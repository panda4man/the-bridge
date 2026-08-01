<?php

namespace App\Filament\Resources\Apps\Pages;

use App\Exceptions\CloneFailed;
use App\Filament\Resources\Apps\AppResource;
use App\Models\App;
use App\Services\AppProvisioner;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Creating an app is not plain CRUD — it git-clones (or adopts) a working
 * copy on disk and seeds its `.env` before any row is written. Ported from
 * reference/src/routes/apps.ts:47-72.
 */
class CreateApp extends CreateRecord
{
    protected static string $resource = AppResource::class;

    /**
     * Set by handleRecordCreation() so getCreatedNotification() can pick
     * between the reference's two messages ("App imported." vs "App created
     * and cloned.") — by the time the notification is built, the toggle's
     * value is no longer in $data.
     */
    private bool $imported = false;

    protected function handleRecordCreation(array $data): Model
    {
        $this->imported = (bool) ($data['skip_clone'] ?? false);
        unset($data['skip_clone']);

        // The form's `path` is the RELATIVE segment; the column stores the
        // absolute one. See AppForm's docblock.
        $data['path'] = AppProvisioner::fullPath((string) $data['path']);

        try {
            app(AppProvisioner::class)->provision(
                $data['path'],
                (string) $data['repo_url'],
                (string) $data['branch'],
                $this->imported,
            );
        } catch (CloneFailed $e) {
            // A clone failure is the operator's problem to fix in the form,
            // not a 500 — it surfaces on the field that caused it, exactly
            // like the reference re-rendering apps/create with an
            // `errors.repo_url` (apps.ts:56-61). `data.` is Filament's form
            // state path prefix.
            throw ValidationException::withMessages([
                'data.repo_url' => $e->getMessage(),
            ]);
        }

        return App::create($data);
    }

    /**
     * The reference redirects to `/` — the app list — not to the new app.
     */
    protected function getRedirectUrl(): string
    {
        return AppResource::getUrl('index');
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title($this->imported ? 'App imported.' : 'App created and cloned.');
    }
}
