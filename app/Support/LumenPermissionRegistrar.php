<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;

class LumenPermissionRegistrar
{
    protected $cache;
    protected $permissionClass;
    protected $roleClass;
    protected $permissions;
    protected $cacheExpirationTime;
    protected $cacheKey;
    protected $isRegistering = false;

    // ✅ Make these PUBLIC (Spatie needs to access them)
    public $pivotRole;
    public $pivotPermission;
    public $teams;

    public function __construct(\Laravel\Lumen\Application $app)
    {
        $config = $app->make('config');

        $this->permissionClass = $config->get('permission.models.permission', \Spatie\Permission\Models\Permission::class);
        $this->roleClass = $config->get('permission.models.role', \Spatie\Permission\Models\Role::class);

        // ✅ Initialize pivot keys from config
        $columnNames = $config->get('permission.column_names', []);
        $this->pivotRole = $columnNames['role_pivot_key'] ?? null;
        $this->pivotPermission = $columnNames['permission_pivot_key'] ?? null;
        $this->teams = $config->get('permission.teams', false);

        try {
            $this->cache = $app->make('cache')->driver();
        } catch (\Exception $e) {
            $this->cache = new \Illuminate\Cache\ArrayStore();
        }

        $cacheConfig = $config->get('permission.cache', []);
        $this->cacheExpirationTime = $cacheConfig['expiration_time'] ?? 86400;
        $this->cacheKey = $cacheConfig['key'] ?? 'spatie.permission.cache';
    }

    public function registerPermissions(): bool
    {
        if ($this->isRegistering) {
            return false;
        }

        $this->isRegistering = true;

        try {
            $this->forgetCachedPermissions();
            $this->permissions = $this->getPermissions();
            return true;
        } catch (\Exception $e) {
            Log::error('Permission registration error: ' . $e->getMessage());
            return false;
        } finally {
            $this->isRegistering = false;
        }
    }

    // public function getPermissions(array $params = [], bool $onlyOne = false): Collection
    // {
    //     if ($this->permissions !== null && empty($params)) {
    //         return $this->permissions;
    //     }

    //     try {
    //         // Don't use cache during registration to prevent recursion
    //         if ($this->isRegistering) {
    //             return $this->getPermissionClass()
    //                 ->with('roles')
    //                 ->get();
    //         }

    //         $cached = $this->cache->get($this->cacheKey);

    //         if ($cached !== null) {
    //             return collect($cached);
    //         }

    //         $permissions = $this->getPermissionClass()
    //             ->with('roles')
    //             ->get();

    //         $this->cache->put($this->cacheKey, $permissions, $this->cacheExpirationTime);

    //         return $permissions;

    //     } catch (\Exception $e) {
    //         Log::error('Get permissions error: ' . $e->getMessage());
    //         return collect([]);
    //     }
    // }

    public function getPermissions(array $params = [], bool $onlyOne = false): EloquentCollection
    {
        if ($this->permissions !== null && empty($params)) {
            return $this->permissions;
        }

        try {
            $permissionClass = $this->getPermissionClass();

            // Jangan pakai cache saat register
            if ($this->isRegistering) {
                return (new $permissionClass)
                    ->with('roles')
                    ->get();
            }

            $cached = $this->cache->get($this->cacheKey);

            if ($cached !== null) {
                // ✅ pastikan selalu EloquentCollection
                return new EloquentCollection($cached);
            }

            $permissions = (new $permissionClass)
                ->with('roles')
                ->get();

            $this->cache->put($this->cacheKey, $permissions, $this->cacheExpirationTime);

            return $permissions;
        } catch (\Exception $e) {
            Log::error('Get permissions error: ' . $e->getMessage());
            // ✅ return EloquentCollection kosong
            return new EloquentCollection();
        }
    }

    public function forgetCachedPermissions()
    {
        $this->permissions = null;

        try {
            return $this->cache->forget($this->cacheKey);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function clearClassPermissions()
    {
        $this->permissions = null;
    }

    public function getPermissionClass(): string
    {
        return $this->permissionClass;
    }

    public function getRoleClass(): string
    {
        return $this->roleClass;
    }

    public function getCacheStore()
    {
        return $this->cache;
    }

    // ✅ Add method that Spatie expects
    public function setPermissionClass($permissionClass)
    {
        $this->permissionClass = $permissionClass;
        return $this;
    }

    public function setRoleClass($roleClass)
    {
        $this->roleClass = $roleClass;
        return $this;
    }

    // ✅ Get cache key
    public function getCacheKey(): string
    {
        return $this->cacheKey;
    }

    // ✅ Get cache expiration time
    public function getCacheExpirationTime()
    {
        return $this->cacheExpirationTime;
    }
}
