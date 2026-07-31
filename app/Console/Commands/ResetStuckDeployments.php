<?php

namespace App\Console\Commands;

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Models\App;
use App\Models\Deployment;
use Illuminate\Console\Command;

/**
 * Ported from reference/src/db.ts resetStuckDeployments().
 *
 * Run on container boot (Phase 7 wires this into the entrypoint) to fail any
 * deployment left `running` or `pending` by an unclean restart, and put any
 * app still marked `deploying` back into `failed`.
 */
class ResetStuckDeployments extends Command
{
    protected $signature = 'bridge:reset-stuck-deployments';

    protected $description = 'Fail deployments left running/pending after an unclean restart';

    public function handle(): int
    {
        $stuck = Deployment::query()
            ->whereIn('status', [DeploymentStatus::Running, DeploymentStatus::Pending])
            ->get();

        foreach ($stuck as $deployment) {
            $deployment->appendLog("\n[Bridge] Container restarted — deployment interrupted.\n");
            $deployment->forceFill([
                'status' => DeploymentStatus::Failed,
                'finished_at' => now(),
            ])->save();
        }

        App::query()->where('status', AppStatus::Deploying)->update(['status' => AppStatus::Failed]);

        $count = $stuck->count();
        $this->info("Reset {$count} stuck deployment(s).");

        return self::SUCCESS;
    }
}
