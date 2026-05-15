<?php

namespace App\Console\Commands;

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Models\App;
use App\Models\Deployment;
use Illuminate\Console\Command;

class ResetStuckDeployments extends Command
{
    protected $signature   = 'deployments:reset-stuck';
    protected $description = 'Mark running/pending deployments as failed (used on startup to clear interrupted jobs)';

    public function handle(): void
    {
        $count = Deployment::whereIn('status', [DeploymentStatus::Running, DeploymentStatus::Pending])
            ->update(['status' => DeploymentStatus::Failed, 'finished_at' => now()]);

        App::where('status', AppStatus::Deploying)
            ->update(['status' => AppStatus::Failed]);

        $this->info("Reset {$count} stuck deployment(s).");
    }
}
