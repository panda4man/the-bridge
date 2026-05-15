<?php

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Jobs\DeployApp;
use App\Models\App;
use App\Models\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('job marks deployment success when all commands exit 0', function () {
    $app = App::factory()->create([
        'path'     => sys_get_temp_dir() . '/bridge-deploy-' . uniqid(),
        'repo_url' => 'https://github.com/octocat/Hello-World.git',
        'branch'   => 'master',
    ]);
    $deployment = Deployment::factory()->create(['app_id' => $app->id, 'status' => 'pending']);

    // Clone the repo so git pull has something to work with
    mkdir($app->path, 0755, true);
    exec("git clone --branch {$app->branch} {$app->repo_url} {$app->path} 2>&1");

    // Mock compose runner — returns exit 0, appends fake output
    $job = new DeployApp($deployment, composeRunner: function (string $subCmd, string $workDir, callable $onOutput): int {
        $onOutput("mock compose {$subCmd}\n");
        return 0;
    });

    $job->handle();

    $deployment->refresh();
    $app->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Success);
    expect($deployment->log)->toContain('mock compose');
    expect($deployment->finished_at)->not->toBeNull();
    expect($app->status)->toBe(AppStatus::Success);

    exec("rm -rf {$app->path}");
});

test('job marks deployment failed when compose exits non-zero', function () {
    $app = App::factory()->create([
        'path'     => sys_get_temp_dir() . '/bridge-fail-' . uniqid(),
        'repo_url' => 'https://github.com/octocat/Hello-World.git',
        'branch'   => 'master',
    ]);
    $deployment = Deployment::factory()->create(['app_id' => $app->id, 'status' => 'pending']);

    mkdir($app->path, 0755, true);
    exec("git clone --branch {$app->branch} {$app->repo_url} {$app->path} 2>&1");

    $job = new DeployApp($deployment, composeRunner: function (string $subCmd, string $workDir, callable $onOutput): int {
        $onOutput("compose error\n");
        return 1;
    });

    $job->handle();

    $deployment->refresh();
    $app->refresh();

    expect($deployment->status)->toBe(DeploymentStatus::Failed);
    expect($app->status)->toBe(AppStatus::Failed);

    exec("rm -rf {$app->path}");
});
