<?php

namespace Victorycodedev\NativephpFetch;

use Illuminate\Support\ServiceProvider;
use Victorycodedev\NativephpFetch\Commands\CopyAssetsCommand;

class FetchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Fetch::class, function () {
            return new Fetch();
        });
    }

    public function boot(): void
    {
        // Register plugin hook commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                CopyAssetsCommand::class,
            ]);
        }
    }
}