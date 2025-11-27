<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run()
    {
        // Reset cached roles and permissions (Spatie + custom Lumen registrar)
        if (app()->bound(\App\Support\LumenPermissionRegistrar::class)) {
            app()[\App\Support\LumenPermissionRegistrar::class]->forgetCachedPermissions();
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Permission list
        $permissions = [
            'event.view','event.create','event.update','event.delete','event.view-all',
            'tiket.view','tiket.scan','tiket.verify',
            'jenis-tiket.view','jenis-tiket.create','jenis-tiket.update','jenis-tiket.delete',
            'transaksi.create','transaksi.view','transaksi.view-all','transaksi.approve','transaksi.reject',
            'user.view','user.create','user.update','user.delete','user.manage-roles',
            'pamflet.view','pamflet.create','pamflet.update','pamflet.delete',
            'pengecekan.view','pengecekan.create',
        ];

        // Create permissions (firstOrCreate)
        foreach ($permissions as $permName) {
            Permission::firstOrCreate([
                'name' => $permName,
                'guard_name' => 'api',
            ]);
        }

        // Utility: get IDs from permission names (unique)
        $allPermissionMap = Permission::whereIn('name', $permissions)
            ->where('guard_name', 'api')
            ->get()
            ->keyBy('name');

        // Helper to get unique ids from names array
        $getIds = function (array $names) use ($allPermissionMap) {
            $ids = [];
            foreach ($names as $n) {
                if (isset($allPermissionMap[$n])) {
                    $ids[] = $allPermissionMap[$n]->id;
                }
            }
            return array_values(array_unique($ids));
        };

        // -------- ADMIN (all perms) ----------
        $admin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'api']);
        // remove existing pivot entries for this role to be safe
        DB::table('role_has_permissions')->where('role_id', $admin->id)->delete();
        $adminIds = Permission::where('guard_name', 'api')->pluck('id')->toArray();
        // Use sync on relation (will replace existing without duplicates)
        $admin->permissions()->sync($adminIds);

        // -------- EO ----------
        $eo = Role::firstOrCreate(['name' => 'EO', 'guard_name' => 'api']);
        DB::table('role_has_permissions')->where('role_id', $eo->id)->delete();
        $eoNames = [
            'event.view','event.create','event.update','event.delete',
            'jenis-tiket.view','jenis-tiket.create','jenis-tiket.update','jenis-tiket.delete',
            'tiket.view','tiket.scan','tiket.verify',
            'transaksi.view',
            'pamflet.view','pamflet.create','pamflet.update','pamflet.delete',
            'pengecekan.view','user.view','user.view-all',
        ];
        $eo->permissions()->sync($getIds($eoNames));

        // -------- PANITIA ----------
        $panitia = Role::firstOrCreate(['name' => 'Panitia', 'guard_name' => 'api']);
        DB::table('role_has_permissions')->where('role_id', $panitia->id)->delete();
        $panitiaNames = ['event.view','tiket.view','tiket.scan','tiket.verify','pengecekan.view','pengecekan.create'];
        $panitia->permissions()->sync($getIds($panitiaNames));

        // -------- USER ----------
        $user = Role::firstOrCreate(['name' => 'User', 'guard_name' => 'api']);
        DB::table('role_has_permissions')->where('role_id', $user->id)->delete();
        $userNames = ['event.view','jenis-tiket.view','tiket.view','transaksi.create','transaksi.view'];
        $user->permissions()->sync($getIds($userNames));

        echo "✅ Permissions and Roles seeded successfully!\n";
    }
}
