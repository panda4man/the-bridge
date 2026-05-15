<?php

use App\Http\Controllers\AppController;
use App\Http\Controllers\DeploymentController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AppController::class, 'index']);
Route::resource('apps', AppController::class)->except(['index']);
Route::post('/apps/{app}/deploy', [AppController::class, 'deploy'])->name('apps.deploy');
Route::get('/deployments/{deployment}', [DeploymentController::class, 'show'])->name('deployments.show');
Route::get('/deployments/{deployment}/stream', [DeploymentController::class, 'stream'])->name('deployments.stream');
