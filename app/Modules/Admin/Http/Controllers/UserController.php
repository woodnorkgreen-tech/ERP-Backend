<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * @OA\Schema(
 *     schema="User",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="John Doe"),
 *     @OA\Property(property="email", type="string", format="email", example="john@example.com"),
 *     @OA\Property(property="is_active", type="boolean", example=true),
 *     @OA\Property(property="department_id", type="integer", nullable=true),
 *     @OA\Property(property="employee_id", type="integer", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class UserController
{
    /**
     * @OA\Get(
     *     path="/api/admin/users",
     *     summary="Get all users with pagination and filters",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Items per page",
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Parameter(
     *         name="department_id",
     *         in="query",
     *         description="Filter by department ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="role_id",
     *         in="query",
     *         description="Filter by role ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="is_active",
     *         in="query",
     *         description="Filter by active status",
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by name, email, or employee name",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Users retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/User")),
     *             @OA\Property(property="meta", type="object",
     *                 @OA\Property(property="total", type="integer"),
     *                 @OA\Property(property="per_page", type="integer"),
     *                 @OA\Property(property="current_page", type="integer"),
     *                 @OA\Property(property="last_page", type="integer")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        \Log::info('UserController::index called', ['user' => Auth::id(), 'request' => $request->all()]);

        $query = User::query();

        // Apply filters
        if ($request->has('department_id') && $request->department_id) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->has('role_id') && $request->role_id) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('roles.id', $request->role_id);
            });
        }

        if ($request->has('role_name') && $request->role_name) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('roles.name', $request->role_name);
            });
        }

        if ($request->has('is_active') && $request->is_active !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhereHas('employee', function ($employeeQuery) use ($search) {
                      $employeeQuery->where('first_name', 'like', "%{$search}%")
                                    ->orWhere('last_name', 'like', "%{$search}%");
                  });
            });
        }

        // Apply pagination if requested
        $perPage = $request->get('per_page', 15);
        $users = $query->with(['employee', 'department', 'roles'])->paginate($perPage);

        \Log::info('UserController::index returning users', [
            'count' => $users->count(),
            'total' => $users->total(),
            'users' => $users->items()
        ]);

        return response()->json([
            'data' => $users->items(),
            'meta' => [
                'total' => $users->total(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
            ]
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/admin/users/available-employees",
     *     summary="Get list of available employees for user creation",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by employee name or email",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Available employees retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="email", type="string"),
     *                 @OA\Property(property="department", type="string", nullable=true)
     *             ))
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function availableEmployees(Request $request): JsonResponse
    {
        \Log::info('AvailableEmployees endpoint called', ['user' => Auth::id(), 'request' => $request->all()]);

        $query = Employee::active()->whereNotIn('id', function ($subQuery) {
            $subQuery->select('employee_id')->from('users')->whereNotNull('employee_id');
        });

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $employees = $query->with('department')->get();

        \Log::info('Available employees query result', [
            'count' => $employees->count(),
            'employees' => $employees->map(function($emp) {
                return [
                    'id' => $emp->id,
                    'name' => $emp->name,
                    'email' => $emp->email,
                    'department' => $emp->department ? $emp->department->name : null
                ];
            })
        ]);

        return response()->json([
            'data' => $employees
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/admin/users",
     *     summary="Create a new user from employee",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"employee_id","password","role_ids"},
     *             @OA\Property(property="employee_id", type="integer", example=1),
     *             @OA\Property(property="password", type="string", format="password", example="password123"),
     *             @OA\Property(property="password_confirmation", type="string", example="password123"),
     *             @OA\Property(property="role_ids", type="array", @OA\Items(type="integer"), example={1,2})
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="User created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", ref="#/components/schemas/User")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id|unique:users,employee_id',
            'password' => 'required|string|min:8|confirmed',
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'exists:roles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Get employee data
        $employee = Employee::findOrFail($request->employee_id);

        // Create user
        $user = User::create([
            'name' => $employee->name,
            'email' => $employee->email,
            'password' => Hash::make($request->password),
            'employee_id' => $employee->id,
            'department_id' => $employee->department_id,
            'is_active' => true,
        ]);

        // Assign roles
        $roles = Role::whereIn('id', $request->role_ids)->get();
        $user->syncRoles($roles);

        return response()->json([
            'message' => 'User created successfully',
            'data' => $user->load(['employee', 'department', 'roles'])
        ], 201);
    }

    /**
     * @OA\Get(
     *     path="/api/admin/users/{user}",
     *     summary="Get user details",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="user",
     *         in="path",
     *         required=true,
     *         description="User ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User details retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/User")
     *         )
     *     ),
     *     @OA\Response(response=404, description="User not found"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'data' => $user->load(['employee', 'department', 'roles'])
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/admin/users/{user}",
     *     summary="Update user details",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="user",
     *         in="path",
     *         required=true,
     *         description="User ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", format="email", example="john@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="newpassword123"),
     *             @OA\Property(property="password_confirmation", type="string", example="newpassword123"),
     *             @OA\Property(property="department_id", type="integer", nullable=true),
     *             @OA\Property(property="is_active", type="boolean", example=true),
     *             @OA\Property(property="role_ids", type="array", @OA\Items(type="integer"), example={1,2})
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", ref="#/components/schemas/User")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=404, description="User not found"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function update(Request $request, User $user): JsonResponse
    {
        try {
            \Log::info('User update request received', [
                'user_id' => $user->id,
                'request_data' => $request->all()
            ]);

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'email' => ['sometimes', 'required', 'email', Rule::unique('users')->ignore($user->id)],
                'password' => 'sometimes|nullable|string|min:8|confirmed',
                'department_id' => 'sometimes|nullable|exists:departments,id',
                'is_active' => 'sometimes|boolean',
                'role_ids' => 'sometimes|array',
                'role_ids.*' => 'exists:roles,id',
            ]);

            if ($validator->fails()) {
                \Log::warning('User update validation failed', [
                    'user_id' => $user->id,
                    'errors' => $validator->errors()
                ]);
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $updateData = $request->only(['name', 'email', 'department_id', 'is_active']);
            \Log::info('Update data prepared', ['update_data' => $updateData]);

            // Handle password update if provided
            if ($request->has('password') && !empty($request->password)) {
                $updateData['password'] = Hash::make($request->password);
                \Log::info('Password update included for user ' . $user->id);
            }

            \Log::info('Updating user with data', ['user_id' => $user->id, 'data' => $updateData]);
            $user->update($updateData);

            // Handle role synchronization if role_ids are provided
            if ($request->has('role_ids') && is_array($request->role_ids)) {
                try {
                    $roles = Role::whereIn('id', $request->role_ids)->get();
                    $user->syncRoles($roles);
                    \Log::info('Roles synced for user ' . $user->id, ['role_ids' => $request->role_ids]);
                } catch (\Exception $e) {
                    // Log the error but don't fail the entire update
                    \Log::error('Failed to sync roles for user ' . $user->id . ': ' . $e->getMessage());
                }
            }

            \Log::info('User update completed successfully', ['user_id' => $user->id]);

            return response()->json([
                'message' => 'User updated successfully',
                'data' => $user->load(['employee', 'department', 'roles'])
            ]);

        } catch (\Exception $e) {
            \Log::error('User update failed with exception', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'An error occurred while updating the user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/project-officers",
     *     summary="Get list of project officers for enquiry assignment",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Project officers retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="email", type="string")
     *             ))
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function getProjectOfficers(Request $request): JsonResponse
    {
        $query = User::query();

        // Filter by Project Officer role
        $query->whereHas('roles', function ($q) {
            $q->where('roles.name', 'Project Officer');
        });

        // Apply active filter
        $query->where('is_active', true);

        $users = $query->with(['employee', 'department', 'roles'])->get();

        // Format for frontend
        $formattedUsers = $users->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ];
        });

        return response()->json([
            'data' => $formattedUsers
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/admin/users/{user}",
     *     summary="Delete user",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="user",
     *         in="path",
     *         required=true,
     *         description="User ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=404, description="User not found"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully'
        ]);
    }
}
