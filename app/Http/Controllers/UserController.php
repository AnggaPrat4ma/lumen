<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class UserController extends Controller
{
    /**
     * Get all users with their roles and permissions
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search');
            $role = $request->get('role');
            $status = $request->get('status');

            $query = User::query()->with(['roles', 'permissions']);

            // Search by name, email, or phone
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('nama', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            // Filter by role
            if ($role) {
                $query->whereHas('roles', function ($q) use ($role) {
                    $q->where('name', $role);
                });
            }

            // Filter by status
            if ($status) {
                $query->where('status', $status);
            }

            $users = $query->orderBy('id_user', 'desc')->paginate($perPage);

            // Format response
            $users->getCollection()->transform(function ($user) {
                return [
                    'id_user' => $user->id_user,
                    'nama' => $user->nama,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'photo' => $user->photo,
                    'status' => $user->status,
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                    'total_transactions' => $user->getTotalTransactions(),
                    'total_tickets' => $user->getTotalTickets(),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Users retrieved successfully',
                'data' => $users
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching users: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single user detail with roles and permissions
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $user = User::with(['roles.permissions', 'permissions'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'User retrieved successfully',
                'data' => [
                    'id_user' => $user->id_user,
                    'nama' => $user->nama,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'photo' => $user->photo,
                    'status' => $user->status,
                    'roles' => $user->roles->map(function ($role) {
                        return [
                            'id' => $role->id,
                            'name' => $role->name,
                            'guard_name' => $role->guard_name,
                            'permissions' => $role->permissions->pluck('name')
                        ];
                    }),
                    'direct_permissions' => $user->permissions->pluck('name'),
                    'all_permissions' => $user->getAllPermissions()->pluck('name'),
                    'statistics' => [
                        'total_transactions' => $user->getTotalTransactions(),
                        'total_paid_transactions' => $user->getTotalPaidTransactions(),
                        'total_tickets' => $user->getTotalTickets(),
                        'total_active_tickets' => $user->getTotalActiveTickets(),
                        'total_spending' => $user->getTotalSpending(),
                        'formatted_total_spending' => $user->getFormattedTotalSpending(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'User not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Create new user (Admin only)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $this->validate($request, [
                'nama' => 'required|string|max:100',
                'email' => 'required|email|unique:user,email',
                'phone' => 'nullable|string|max:20',
                'firebase_uid' => 'required|string|unique:user,firebase_uid',
                'photo' => 'nullable|string',
                'status' => 'nullable|in:active,inactive',
                'roles' => 'nullable|array',
                'roles.*' => 'exists:roles,name'
            ]);

            $user = User::create([
                'nama' => $request->nama,
                'email' => $request->email,
                'phone' => $request->phone,
                'firebase_uid' => $request->firebase_uid,
                'photo' => $request->photo,
                'status' => $request->status ?? 'active'
            ]);

            // Assign roles if provided
            if ($request->has('roles') && is_array($request->roles)) {
                $user->assignRole($request->roles);
            }

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => [
                    'id_user' => $user->id_user,
                    'nama' => $user->nama,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'status' => $user->status,
                    'roles' => $user->getRoleNames()
                ]
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user information
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $this->validate($request, [
                'nama' => 'nullable|string|max:100',
                'email' => 'nullable|email|unique:user,email,' . $id . ',id_user',
                'phone' => 'nullable|string|max:20',
                'photo' => 'nullable|string',
                'status' => 'nullable|in:active,inactive'
            ]);

            $user->update($request->only([
                'nama',
                'email',
                'phone',
                'photo',
                'status'
            ]));

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => [
                    'id_user' => $user->id_user,
                    'nama' => $user->nama,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'photo' => $user->photo,
                    'status' => $user->status,
                    'roles' => $user->getRoleNames()
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete user (soft delete by setting status to inactive)
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $user = User::findOrFail($id);

            // Prevent deleting own account
            if (auth()->user()->id_user === $user->id_user) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete your own account'
                ], 403);
            }

            // Set status to inactive instead of hard delete
            $user->update(['status' => 'inactive']);

            return response()->json([
                'success' => true,
                'message' => 'User deactivated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign role to user
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function assignRole(Request $request, $id)
    {
        try {
            $this->validate($request, [
                'role' => 'required|string|exists:roles,name'
            ]);

            $user = User::findOrFail($id);

            // Check if user already has this role
            if ($user->hasRole($request->role)) {
                return response()->json([
                    'success' => false,
                    'message' => 'User already has this role'
                ], 400);
            }

            $user->assignRole($request->role);

            Log::info('Role assigned to user', [
                'user_id' => $user->id_user,
                'role' => $request->role,
                'assigned_by' => auth()->user()->id_user
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role assigned successfully',
                'data' => [
                    'id_user' => $user->id_user,
                    'nama' => $user->nama,
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name')
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error assigning role: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove role from user
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeRole(Request $request, $id)
    {
        try {
            $this->validate($request, [
                'role' => 'required|string|exists:roles,name'
            ]);

            $user = User::findOrFail($id);

            // Check if user has this role
            if (!$user->hasRole($request->role)) {
                return response()->json([
                    'success' => false,
                    'message' => 'User does not have this role'
                ], 400);
            }

            // Prevent removing Admin role from self
            if ($request->role === 'Admin' && auth()->user()->id_user === $user->id_user) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot remove Admin role from yourself'
                ], 403);
            }

            $user->removeRole($request->role);

            Log::info('Role removed from user', [
                'user_id' => $user->id_user,
                'role' => $request->role,
                'removed_by' => auth()->user()->id_user
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Role removed successfully',
                'data' => [
                    'id_user' => $user->id_user,
                    'nama' => $user->nama,
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name')
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error removing role: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user permissions
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserPermissions($id)
    {
        try {
            $user = User::with(['roles.permissions', 'permissions'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'User permissions retrieved successfully',
                'data' => [
                    'id_user' => $user->id_user,
                    'nama' => $user->nama,
                    'roles' => $user->roles->map(function ($role) {
                        return [
                            'name' => $role->name,
                            'permissions' => $role->permissions->pluck('name')
                        ];
                    }),
                    'direct_permissions' => $user->permissions->pluck('name'),
                    'all_permissions' => $user->getAllPermissions()->pluck('name')->unique()->values()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user permissions: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user permissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Give permission directly to user (bypassing role)
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function givePermission(Request $request, $id)
    {
        try {
            $this->validate($request, [
                'permission' => 'required|string|exists:permissions,name'
            ]);

            $user = User::findOrFail($id);

            // Check if user already has this permission
            if ($user->hasPermissionTo($request->permission)) {
                return response()->json([
                    'success' => false,
                    'message' => 'User already has this permission (either directly or via role)'
                ], 400);
            }

            $user->givePermissionTo($request->permission);

            Log::info('Permission given to user', [
                'user_id' => $user->id_user,
                'permission' => $request->permission,
                'given_by' => auth()->user()->id_user
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission given successfully',
                'data' => [
                    'id_user' => $user->id_user,
                    'nama' => $user->nama,
                    'direct_permissions' => $user->permissions->pluck('name'),
                    'all_permissions' => $user->getAllPermissions()->pluck('name')
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error giving permission: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to give permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Revoke permission from user
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function revokePermission(Request $request, $id)
    {
        try {
            $this->validate($request, [
                'permission' => 'required|string|exists:permissions,name'
            ]);

            $user = User::findOrFail($id);

            // Check if user has this permission directly (not via role)
            if (!$user->permissions->contains('name', $request->permission)) {
                return response()->json([
                    'success' => false,
                    'message' => 'User does not have this permission directly. It may be inherited from a role.'
                ], 400);
            }

            $user->revokePermissionTo($request->permission);

            Log::info('Permission revoked from user', [
                'user_id' => $user->id_user,
                'permission' => $request->permission,
                'revoked_by' => auth()->user()->id_user
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission revoked successfully',
                'data' => [
                    'id_user' => $user->id_user,
                    'nama' => $user->nama,
                    'direct_permissions' => $user->permissions->pluck('name'),
                    'all_permissions' => $user->getAllPermissions()->pluck('name')
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error revoking permission: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to revoke permission',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}