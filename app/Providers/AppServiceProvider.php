<?php

namespace App\Providers;

use App\Services\Process\ProcessRunner;
use App\Services\Process\SymfonyProcessRunner;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProcessRunner::class, SymfonyProcessRunner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
