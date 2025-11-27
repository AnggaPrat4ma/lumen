<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Auth\Access\Gate;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Contracts\Role as RoleContract;
use App\Support\LumenPermissionRegistrar;
use Illuminate\Support\Facades\Log;

class LumenPermissionServiceProvider extends ServiceProvider
{
    public function register()
    {
        // ❌ HAPUS INI - tidak bisa di Service Provider
        // $this->app->configure('permission');

        // ✅ Simple singleton registration
        $this->app->singleton(LumenPermissionRegistrar::class, function ($app) {
            return new LumenPermissionRegistrar($app);
        });

        $this->app->alias(LumenPermissionRegistrar::class, \Spatie\Permission\PermissionRegistrar::class);

        $this->registerModelBindings();
        $this->registerCache();
    }

    public function boot()
    {
        // ✅ Delay permission registration
        // $this->app->booted(function () {
        //     try {
        //         $permissionLoader = $this->app->make(LumenPermissionRegistrar::class);
        //         $permissionLoader->registerPermissions();
        //     } catch (\Exception $e) {
        //         Log::error('Permission boot error: ' . $e->getMessage());
        //     }
        // });

        $this->registerGates();
    }

    protected function registerModelBindings()
    {
        $config = config('permission.models', []);

        if (empty($config)) {
            return;
        }

        $this->app->bind(PermissionContract::class, $config['permission'] ?? \Spatie\Permission\Models\Permission::class);
        $this->app->bind(RoleContract::class, $config['role'] ?? \Spatie\Permission\Models\Role::class);
    }

    protected function registerCache()
    {
        // ✅ Simpler cache registration
        if (!$this->app->bound('cache')) {
            $this->app->singleton('cache', function ($app) {
                return new \Illuminate\Cache\CacheManager($app);
            });
        }

        if (!$this->app->bound('cache.store')) {
            $this->app->singleton('cache.store', function ($app) {
                return $app->make('cache')->driver();
            });
        }
    }

    protected function registerGates()
    {
        try {
            $this->app->make(Gate::class)->before(function ($user, $ability) {
                if (method_exists($user, 'checkPermissionTo')) {
                    try {
                        return $user->checkPermissionTo($ability) ?: null;
                    } catch (\Exception $e) {
                        return null;
                    }
                }
            });
        } catch (\Exception $e) {
            Log::error('Gate registration error: ' . $e->getMessage());
        }
    }
}
