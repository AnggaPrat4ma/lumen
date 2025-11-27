<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function index(Request $request)
    {
        try {
            $groupBy = $request->get('group_by', null);
            $withRoles = $request->get('with_roles', false);

            $query = Permission::query()->where('guard_name', 'api');

            if ($withRoles) {
                $query->with('roles');
            }

            $permissions = $query->orderBy('name', 'asc')->get();

            $data = $permissions->map(function ($permission) use ($withRoles) {
                $item = [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'guard_name' => $permission->guard_name,
                    'created_at' => $permission->created_at,
                    'updated_at' => $permission->updated_at,
                ];

                if ($withRoles) {
                    $item['roles'] = $permission->roles->pluck('name');
                    $item['roles_count'] = $permission->roles->count();
                }

                return $item;
            });

            if ($groupBy === 'prefix') {
                $grouped = [];
                foreach ($data as $permission) {
                    $parts = explode('.', $permission['name']);
                    $prefix = $parts[0] ?? 'other';
                    
                    if (!isset($grouped[$prefix])) {
                        $grouped[$prefix] = [];
                    }
                    $grouped[$prefix][] = $permission;
                }
                $data = $grouped;
            }

            return response()->json([
                'success' => true,
                'message' => 'Permissions retrieved successfully',
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching permissions: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $this->validate($request, [
                'name' => 'required|string|unique:permissions,name|max:255',
                'assign_to_roles' => 'nullable|array',
                'assign_to_roles.*' => 'exists:roles,name'
            ]);

            $permission = Permission::create([
                'name' => $request->name,
                'guard_name' => 'api'
            ]);

            if ($request->has('assign_to_roles') && is_array($request->assign_to_roles)) {
                foreach ($request->assign_to_roles as $roleName) {
                    $role = \Spatie\Permission\Models\Role::where('name', $roleName)->first();
                    if ($role) {
                        $role->givePermissionTo($permission);
                    }
                }
            }

            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            Log::info('Permission created', [
                'permission_id' => $permission->id,
                'permission_name' => $permission->name,
                'created_by' => auth()->user()->id_user
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission created successfully',
                'data' => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'guard_name' => $permission->guard_name,
                    'assigned_to_roles' => $request->assign_to_roles ?? [],
                    'created_at' => $permission->created_at
                ]
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating permission: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $permission = Permission::findOrFail($id);

            $this->validate($request, [
                'name' => 'nullable|string|unique:permissions,name,' . $id . ',id|max:255',
            ]);

            if ($request->has('name')) {
                $oldName = $permission->name;
                $permission->name = $request->name;
                $permission->save();

                app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

                Log::info('Permission updated', [
                    'permission_id' => $permission->id,
                    'old_name' => $oldName,
                    'new_name' => $permission->name,
                    'updated_by' => auth()->user()->id_user
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Permission updated successfully',
                'data' => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'guard_name' => $permission->guard_name,
                    'updated_at' => $permission->updated_at
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating permission: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $permission = Permission::findOrFail($id);

            $rolesCount = $permission->roles()->count();
            if ($rolesCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete permission. It is assigned to {$rolesCount} role(s). Please remove it from all roles first."
                ], 400);
            }

            $usersCount = $permission->users()->count();
            if ($usersCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete permission. {$usersCount} user(s) have this permission directly. Please revoke it from all users first."
                ], 400);
            }

            $permissionName = $permission->name;
            $permission->delete();

            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            Log::info('Permission deleted', [
                'permission_id' => $id,
                'permission_name' => $permissionName,
                'deleted_by' => auth()->user()->id_user
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting permission: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $permission = Permission::with(['roles', 'users'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Permission retrieved successfully',
                'data' => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'guard_name' => $permission->guard_name,
                    'created_at' => $permission->created_at,
                    'updated_at' => $permission->updated_at,
                    'roles' => $permission->roles->map(function ($role) {
                        return [
                            'id' => $role->id,
                            'name' => $role->name,
                            'users_count' => $role->users()->count()
                        ];
                    }),
                    'direct_users' => $permission->users->map(function ($user) {
                        return [
                            'id_user' => $user->id_user,
                            'nama' => $user->nama,
                            'email' => $user->email
                        ];
                    }),
                    'statistics' => [
                        'total_roles' => $permission->roles->count(),
                        'total_direct_users' => $permission->users->count(),
                        'total_users_via_roles' => $permission->roles->sum(function ($role) {
                            return $role->users()->count();
                        })
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching permission: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Permission not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }
}