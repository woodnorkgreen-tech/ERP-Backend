<?php

namespace App\Modules\Admin\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController
{
    /**
     * Display a listing of roles.
     */
    public function index(Request $request): JsonResponse
    {
        // Debug logging for permission check
        $user = auth()->user();
        \Log::info('RoleController@index - User attempting to fetch roles', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'user_permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
            'user_roles' => $user->roles->pluck('name')->toArray(),
            'has_role_read' => $user->hasPermissionTo('role.read'),
            'request_data' => $request->all()
        ]);

        $query = Role::query();

        // Apply search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $roles = $query->with('permissions')->get();

        return response()->json([
            'data' => $roles->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                    'permissions' => $role->permissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'name' => $permission->name,
                            'description' => $permission->description ?? $permission->name,
                        ];
                    }),
                    'user_count' => $role->users()->count(),
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                ];
            })
        ]);
    }

    /**
     * Display the specified role.
     */
    public function show(Role $role): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'permissions' => $role->permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'description' => $permission->description ?? $permission->name,
                    ];
                }),
                'users' => $role->users->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ];
                }),
                'created_at' => $role->created_at,
                'updated_at' => $role->updated_at,
            ]
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:roles,name',
            'description' => 'nullable|string|max:255',
            'permission_ids' => 'array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        $role = Role::create([
            'name' => $request->name,
            'description' => $request->description,
            'guard_name' => 'web',
        ]);

        if ($request->has('permission_ids')) {
            $permissions = Permission::whereIn('id', $request->permission_ids)->get();
            $role->syncPermissions($permissions);
        }

        return response()->json([
            'message' => 'Role created successfully',
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'permissions' => $role->permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'description' => $permission->description ?? $permission->name,
                    ];
                }),
            ]
        ], 201);
    }

    /**
     * Update the specified role.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name,' . $role->id],
            'description' => 'nullable|string|max:255',
            'permission_ids' => 'array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        $role->update([
            'name' => $request->name,
            'description' => $request->description,
        ]);

        if ($request->has('permission_ids')) {
            $permissions = Permission::whereIn('id', $request->permission_ids)->get();
            $role->syncPermissions($permissions);
        }

        return response()->json([
            'message' => 'Role updated successfully',
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'permissions' => $role->permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'description' => $permission->description ?? $permission->name,
                    ];
                }),
            ]
        ]);
    }

    /**
     * Remove the specified role.
     */
    public function destroy(Role $role): JsonResponse
    {
        // Check if role has users assigned
        if ($role->users()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete role that has users assigned to it'
            ], 422);
        }

        $role->delete();

        return response()->json([
            'message' => 'Role deleted successfully'
        ]);
    }
}
