<?php

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Models\App;
use App\Models\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('App status casts to AppStatus enum', function () {
    $app = App::factory()->create(['status' => 'idle']);
    expect($app->status)->toBeInstanceOf(AppStatus::class);
    expect($app->status)->toBe(AppStatus::Idle);
});

test('App has many deployments', function () {
    $app = App::factory()->create();
    Deployment::factory()->create(['app_id' => $app->id]);
    expect($app->deployments)->toHaveCount(1);
});

test('Deployment belongs to App', function () {
    $app = App::factory()->create();
    $deployment = Deployment::factory()->create(['app_id' => $app->id]);
    expect($deployment->app->id)->toBe($app->id);
});

test('Deployment status casts to DeploymentStatus enum', function () {
    $deployment = Deployment::factory()->create(['status' => 'pending']);
    expect($deployment->status)->toBeInstanceOf(DeploymentStatus::class);
    expect($deployment->status)->toBe(DeploymentStatus::Pending);
});
