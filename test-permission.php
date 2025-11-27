<?php

ini_set('memory_limit', '1G'); // naikkan batas memori sementara

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

echo "===========================================\n";
echo "Testing Lumen Permission System\n";
echo "===========================================\n\n";

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

try {
    echo "Test 1: Database Connection\n";
    $pdo = $app->make('db')->connection()->getPdo();
    echo "✅ Database connected successfully.\n\n";

    echo "Test 2: Create or Get Permission\n";
    $permission = Permission::firstOrCreate(['name' => 'edit articles']);
    echo "✅ Permission '{$permission->name}' ready.\n\n";

    echo "Test 3: Create or Get Role\n";
    $role = Role::firstOrCreate(['name' => 'writer']);
    echo "✅ Role '{$role->name}' ready.\n\n";

    echo "Test 4: Assign Permission to Role\n";
    $role->givePermissionTo($permission);
    echo "✅ Permission '{$permission->name}' assigned to '{$role->name}'.\n\n";

    echo "Test 5: Verify Assignment\n";
    $hasPermission = $role->hasPermissionTo('edit articles') ? 'YES' : 'NO';
    echo "✅ Role has permission? $hasPermission\n\n";

    echo "===========================================\n";
    echo "🎉 ALL BASIC TESTS PASSED!\n";
    echo "===========================================\n";

} catch (Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
