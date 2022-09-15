<?php

namespace Eduardosp6\RouterTool;

use Illuminate\Support\ServiceProvider;

class RouterToolServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/router-tool.php' => config_path('router-tool.php'),
        ], 'config');
    }

    public function register()
    {
        $this->app->singleton(RouterTool::class, function($app) {
            return new RouterTool();
        });

        $this->mergeConfigFrom(
            __DIR__ . '/../config/router-tool.php',
            'router-tool'
        );
    }
}