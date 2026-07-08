<?php

namespace App\Modules\Admin\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

class PermissionController
{
    /**
     * Display a listing of permissions.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Permission::query();

        // Apply search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $permissions = $query->get();

        return response()->json([
            'data' => $permissions->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'description' => \App\Constants\Permissions::getLabel($permission->name),
                    'created_at' => $permission->created_at,
                    'updated_at' => $permission->updated_at,
                ];
            })
        ]);
    }

    /**
     * Display permissions grouped by module (the segment before the dot in the
     * permission name, e.g. "user.create" -> "user"). Drives a grouped permission
     * picker in the role editor instead of one flat list.
     */
    public function grouped(Request $request): JsonResponse
    {
        $groups = Permission::orderBy('name')->get()
            ->groupBy(fn ($permission) => explode('.', $permission->name)[0] ?: 'general')
            ->map(fn ($permissions, $module) => [
                'module'      => $module,
                'label'       => ucwords(str_replace('_', ' ', $module)),
                'permissions' => $permissions->map(fn ($permission) => [
                    'id'          => $permission->id,
                    'name'        => $permission->name,
                    'description' => \App\Constants\Permissions::getLabel($permission->name),
                ])->values(),
            ])
            ->values();

        return response()->json(['data' => $groups]);
    }

    /**
     * Display the specified permission.
     */
    public function show(Permission $permission): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $permission->id,
                'name' => $permission->name,
                'description' => \App\Constants\Permissions::getLabel($permission->name),
                'created_at' => $permission->created_at,
                'updated_at' => $permission->updated_at,
            ]
        ]);
    }
}