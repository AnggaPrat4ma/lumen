<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

class PermissionServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Daftarkan PermissionRegistrar ke container
        $this->app->singleton(PermissionRegistrar::class, function ($app) {
            return new PermissionRegistrar($app);
        });
    }

    /**
     * @var \Laravel\Lumen\Application $app
     */

    public function boot()
    {
        /** @var \Laravel\Lumen\Application $app */
        $app = $this->app;

        if (method_exists($app, 'configure')) {
            $app->configure('permission');
        }

        $this->mergeConfigFrom(
            __DIR__ . '/../../vendor/spatie/laravel-permission/config/permission.php',
            'permission'
        );

        $this->registerModelBindings();
    }

    protected function registerModelBindings()
    {
        $config = $this->app['config']->get('permission.models');

        if (!$config) {
            return;
        }

        $this->app->bind(\Spatie\Permission\Contracts\Permission::class, $config['permission']);
        $this->app->bind(\Spatie\Permission\Contracts\Role::class, $config['role']);
    }
}
