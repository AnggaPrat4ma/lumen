<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        try {
            $withPermissions = $request->get('with_permissions', true);
            $withUserCount = $request->get('with_user_count', true);

            $query = Role::query()->where('guard_name', 'api');

            if ($withPermissions) {
                $query->with('permissions');
            }

            $roles = $query->orderBy('name', 'asc')->get();

            $roles = $roles->map(function ($role) use ($withPermissions, $withUserCount) {
                $data = [
                    'id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                ];

                if ($withPermissions) {
                    $data['permissions'] = $role->permissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'name' => $permission->name,
                            'guard_name' => $permission->guard_name
                        ];
                    });
                }

                if ($withUserCount) {
                    $data['users_count'] = $role->users()->count();
                }

                return $data;
            });

            return response()->json([
                'success' => true,
                'message' => 'Roles retrieved successfully',
                'data' => $roles
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching roles: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $this->validate($request, [
                'name' => 'required|string|unique:roles,name|max:255',
                'permissions' => 'nullable|array',
                'permissions.*' => 'exists:permissions,name'
            ]);

            $role = Role::create([
                'name' => $request->name,
                'guard_name' => 'api'
            ]);

            if ($request->has('permissions') && is_array($request->permissions)) {
                $role->givePermissionTo($request->permissions);
            }

            Log::info('Role created', [
                'role_id' => $role->id,
                'role_name' => $role->name,
                'created_by' => auth()->user()->id_user
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role created successfully',
                'data' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'permissions' => $role->permissions->pluck('name'),
                    'created_at' => $role->created_at
                ]
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating role: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $role = Role::findOrFail($id);

            if (in_array($role->name, ['Admin', 'EO', 'User'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update default system roles'
                ], 403);
            }

            $this->validate($request, [
                'name' => 'nullable|string|unique:roles,name,' . $id . ',id|max:255',
            ]);

            if ($request->has('name')) {
                $role->name = $request->name;
                $role->save();
            }

            Log::info('Role updated', [
                'role_id' => $role->id,
                'role_name' => $role->name,
                'updated_by' => auth()->user()->id_user
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role updated successfully',
                'data' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'permissions' => $role->permissions->pluck('name'),
                    'updated_at' => $role->updated_at
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating role: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $role = Role::findOrFail($id);

            if (in_array($role->name, ['Admin', 'EO', 'User'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete default system roles'
                ], 403);
            }

            $usersCount = $role->users()->count();
            if ($usersCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete role. {$usersCount} user(s) still have this role."
                ], 400);
            }

            $roleName = $role->name;
            $role->delete();

            Log::info('Role deleted', [
                'role_id' => $id,
                'role_name' => $roleName,
                'deleted_by' => auth()->user()->id_user
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting role: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function assignPermission(Request $request, $id)
    {
        try {
            $this->validate($request, [
                'permission' => 'required|string|exists:permissions,name',
            ]);

            $role = Role::findOrFail($id);

            if ($role->hasPermissionTo($request->permission)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Role already has this permission'
                ], 400);
            }

            $role->givePermissionTo($request->permission);
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            Log::info('Permission assigned to role', [
                'role_id' => $role->id,
                'role_name' => $role->name,
                'permission' => $request->permission,
                'assigned_by' => auth()->user()->id_user
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission assigned successfully',
                'data' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name')
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error assigning permission to role: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function removePermission(Request $request, $id)
    {
        try {
            $this->validate($request, [
                'permission' => 'required|string|exists:permissions,name',
            ]);

            $role = Role::findOrFail($id);

            if (!$role->hasPermissionTo($request->permission)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Role does not have this permission'
                ], 400);
            }

            $role->revokePermissionTo($request->permission);
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            Log::info('Permission removed from role', [
                'role_id' => $role->id,
                'role_name' => $role->name,
                'permission' => $request->permission,
                'removed_by' => auth()->user()->id_user
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission removed successfully',
                'data' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name')
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error removing permission from role: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}