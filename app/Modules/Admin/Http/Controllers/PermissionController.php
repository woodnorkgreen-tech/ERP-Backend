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
                    'description' => $permission->name, // Use name as description for now
                    'created_at' => $permission->created_at,
                    'updated_at' => $permission->updated_at,
                ];
            })
        ]);
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
                'description' => $permission->name,
                'created_at' => $permission->created_at,
                'updated_at' => $permission->updated_at,
            ]
        ]);
    }
}