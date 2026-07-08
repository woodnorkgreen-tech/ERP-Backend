<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Models\ActionLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController
{
    /**
     * Core roles the codebase authorises against by name (hasRole('Admin'), etc.).
     * Renaming or deleting these would silently break authorization everywhere, so
     * they are protected — their permissions/description may still be edited.
     */
    private const PROTECTED_ROLES = ['Super Admin', 'Admin', 'HR', 'Project Manager', 'Project Officer'];

    /**
     * Record a role-management action in the audit trail.
     */
    private function audit(string $action, Role $role, array $details = []): void
    {
        ActionLog::create([
            'user_id'       => Auth::id(),
            'action'        => $action,
            'loggable_type' => Role::class,
            'loggable_id'   => $role->id,
            'original_data' => null,
            'changed_data'  => $details ?: null,
            'ip_address'    => request()->ip(),
            'user_agent'    => request()->userAgent(),
        ]);
    }

    /**
     * Display a listing of roles.
     */
    public function index(Request $request): JsonResponse
    {
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

        $this->audit('role_created', $role, [
            'name'        => $role->name,
            'permissions' => $role->permissions->pluck('name')->all(),
        ]);

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

        // Protect core roles from being renamed — the codebase authorises by role name.
        if (in_array($role->name, self::PROTECTED_ROLES, true) && $request->name !== $role->name) {
            return response()->json([
                'message' => "The '{$role->name}' role is a system role and cannot be renamed. You may still edit its permissions.",
            ], 422);
        }

        $permissionsBefore = $role->permissions->pluck('name')->sort()->values()->all();

        $role->update([
            'name' => $request->name,
            'description' => $request->description,
        ]);

        if ($request->has('permission_ids')) {
            $permissions = Permission::whereIn('id', $request->permission_ids)->get();
            $role->syncPermissions($permissions);
        }

        $permissionsAfter = $role->fresh()->permissions->pluck('name')->sort()->values()->all();
        $this->audit('role_updated', $role, [
            'name'             => $role->name,
            'permissions_from' => $permissionsBefore,
            'permissions_to'   => $permissionsAfter,
        ]);

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
     * Clone an existing role (name + permissions) into a new role.
     */
    public function clone(Request $request, Role $role): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:255|unique:roles,name',
            'description' => 'nullable|string|max:255',
        ]);

        $clone = Role::create([
            'name'        => $request->name,
            'description' => $request->description ?? ('Copy of ' . $role->name),
            'guard_name'  => 'web',
        ]);

        $clone->syncPermissions($role->permissions);

        $this->audit('role_cloned', $clone, [
            'cloned_from' => $role->name,
            'permissions' => $clone->permissions->pluck('name')->all(),
        ]);

        return response()->json([
            'message' => 'Role cloned successfully',
            'data' => [
                'id'          => $clone->id,
                'name'        => $clone->name,
                'description' => $clone->description,
                'permissions' => $clone->permissions->map(fn ($permission) => [
                    'id'          => $permission->id,
                    'name'        => $permission->name,
                    'description' => $permission->description ?? $permission->name,
                ]),
            ],
        ], 201);
    }

    /**
     * Remove the specified role.
     */
    public function destroy(Role $role): JsonResponse
    {
        // Core roles are referenced by name across the codebase — never deletable.
        if (in_array($role->name, self::PROTECTED_ROLES, true)) {
            return response()->json([
                'message' => "The '{$role->name}' role is a system role and cannot be deleted.",
            ], 422);
        }

        // Check if role has users assigned
        if ($role->users()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete role that has users assigned to it'
            ], 422);
        }

        $this->audit('role_deleted', $role, ['name' => $role->name]);

        $role->delete();

        return response()->json([
            'message' => 'Role deleted successfully'
        ]);
    }
}
