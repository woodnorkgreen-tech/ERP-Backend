<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Constants\Permissions;
use App\Models\ActionLog;
use App\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
     * Privileged roles that may only be granted by a caller who already holds the
     * required tier. Without this, any USER_UPDATE/USER_CREATE holder could grant
     * themselves (or anyone) Super Admin and escalate privileges.
     */
    private const ROLE_GRANT_GUARDS = [
        'Super Admin' => ['Super Admin'],
        'Admin'       => ['Super Admin', 'Admin'],
    ];

    /**
     * Reject the request (403) if the acting user is attempting to grant a
     * privileged role they are not themselves entitled to delegate.
     */
    private function assertCanAssignRoles(array $roleIds): void
    {
        $actor = Auth::user();

        if ($actor && $actor->hasRole('Super Admin')) {
            return; // Super Admin may grant anything.
        }

        $targetRoleNames = Role::whereIn('id', $roleIds)->pluck('name')->all();

        foreach (self::ROLE_GRANT_GUARDS as $protectedRole => $allowedGranters) {
            if (in_array($protectedRole, $targetRoleNames, true)
                && (! $actor || ! $actor->hasRole($allowedGranters))) {
                abort(403, "You are not authorized to assign the '{$protectedRole}' role.");
            }
        }
    }

    /**
     * Whether the given user is the only remaining Super Admin — protected from
     * deletion / deactivation / role removal to prevent locking everyone out.
     */
    private function isLastSuperAdmin(User $user): bool
    {
        return $user->hasRole('Super Admin')
            && User::role('Super Admin')->count() <= 1;
    }

    /**
     * Record an admin action against a user in the audit trail.
     * Details are curated by the caller — never include the password value.
     */
    private function auditUser(string $action, User $user, array $details = []): void
    {
        ActionLog::create([
            'user_id'       => Auth::id(),
            'action'        => $action,
            'loggable_type' => User::class,
            'loggable_id'   => $user->id,
            'original_data' => null,
            'changed_data'  => $details ?: null,
            'ip_address'    => request()->ip(),
            'user_agent'    => request()->userAgent(),
        ]);
    }

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
        $users = $query->with(['employee', 'department', 'roles'])
            ->orderBy('name', 'asc')
            ->paginate($perPage);

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

        // Block privilege escalation via the create path.
        $this->assertCanAssignRoles($request->role_ids);

        // Get employee data
        $employee = Employee::findOrFail($request->employee_id);

        // A login requires a unique email; employee email is now nullable, so guard
        // against creating a user with a null/duplicate email rather than 500-ing on insert.
        if (empty($employee->email)) {
            throw ValidationException::withMessages([
                'employee_id' => 'This employee has no email address on file. Add one before creating a login.',
            ]);
        }

        if (User::where('email', $employee->email)->exists()) {
            throw ValidationException::withMessages([
                'employee_id' => "A user account already exists for the email {$employee->email}.",
            ]);
        }

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

        $this->auditUser('user_created', $user, [
            'employee_id' => $user->employee_id,
            'roles'       => $roles->pluck('name')->all(),
        ]);

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
            // NB: do not log $request->all() here — it contains the plaintext password.

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

            // Self-protection / lockout guards.
            $isSelf = Auth::id() === $user->id;
            $deactivating = $request->has('is_active') && ! $request->boolean('is_active');

            if ($deactivating && $isSelf) {
                return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
            }
            if ($deactivating && $this->isLastSuperAdmin($user)) {
                return response()->json(['message' => 'Cannot deactivate the last Super Admin.'], 422);
            }

            // Role-change guards: block privilege escalation and removal of the last Super Admin.
            if ($request->has('role_ids') && is_array($request->role_ids)) {
                $this->assertCanAssignRoles($request->role_ids);

                $keepsSuperAdmin = in_array(
                    'Super Admin',
                    Role::whereIn('id', $request->role_ids)->pluck('name')->all(),
                    true
                );
                if (! $keepsSuperAdmin && $this->isLastSuperAdmin($user)) {
                    return response()->json(['message' => 'Cannot remove the Super Admin role from the last Super Admin.'], 422);
                }
            }

            $updateData = $request->only(['name', 'email', 'department_id', 'is_active']);

            $passwordChanged = false;
            if ($request->has('password') && !empty($request->password)) {
                $updateData['password'] = Hash::make($request->password);
                $passwordChanged = true;
            }

            $rolesBefore = $user->getRoleNames()->sort()->values()->all();

            // Field update + role sync share one transaction: if role sync fails the
            // whole update rolls back instead of silently reporting success (the old
            // inner try/catch swallowed sync errors).
            DB::transaction(function () use ($user, $updateData, $request) {
                $user->update($updateData);

                if ($request->has('role_ids') && is_array($request->role_ids)) {
                    $roles = Role::whereIn('id', $request->role_ids)->get();
                    $user->syncRoles($roles);
                }
            });

            // Audit a curated change set — field names only, never the password value.
            $changedFields = array_values(array_diff(
                array_keys($user->getChanges()),
                ['updated_at', 'created_at', 'password']
            ));
            if ($passwordChanged) {
                $changedFields[] = 'password';
            }

            $rolesAfter = $user->fresh()->getRoleNames()->sort()->values()->all();

            $auditDetails = [];
            if ($changedFields) {
                $auditDetails['fields'] = $changedFields;
            }
            if ($rolesBefore !== $rolesAfter) {
                $auditDetails['roles_from'] = $rolesBefore;
                $auditDetails['roles_to'] = $rolesAfter;
            }
            if ($auditDetails) {
                $this->auditUser('user_updated', $user, $auditDetails);
            }

            return response()->json([
                'message' => 'User updated successfully',
                'data' => $user->load(['employee', 'department', 'roles'])
            ]);

        } catch (ValidationException | \Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // Authorization (403) and validation (422) failures must surface as-is,
            // not be swallowed into a generic 500.
            throw $e;
        } catch (\Exception $e) {
            \Log::error('User update failed with exception', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Do not leak the raw exception message to the client.
            return response()->json([
                'message' => 'An error occurred while updating the user.'
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

        $users = $query->with(['employee', 'department', 'roles'])
            ->orderBy('name', 'asc')
            ->get();

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
        if (Auth::id() === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        if ($this->isLastSuperAdmin($user)) {
            return response()->json(['message' => 'Cannot delete the last Super Admin.'], 422);
        }

        $this->auditUser('user_deleted', $user, [
            'name'  => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->all(),
        ]);

        // Soft delete: the row (and its FK references across the system) is retained
        // and the action is reversible; the user simply disappears from default queries.
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully'
        ]);
    }

    /**
     * Activate a user account.
     */
    public function activate(User $user): JsonResponse
    {
        $result = $this->setActiveStatus($user, true);

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json([
            'message' => 'User activated.',
            'data'    => $user->fresh()->load(['employee', 'department', 'roles']),
        ]);
    }

    /**
     * Deactivate a user account and sign them out of all sessions.
     */
    public function deactivate(User $user): JsonResponse
    {
        $result = $this->setActiveStatus($user, false);

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json([
            'message' => 'User deactivated and signed out of all sessions.',
            'data'    => $user->fresh()->load(['employee', 'department', 'roles']),
        ]);
    }

    /**
     * Bulk activate / deactivate users. Per-user lockout guards are applied;
     * users that cannot be changed are reported in `skipped` rather than failing the batch.
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_ids'   => 'required|array|min:1',
            'user_ids.*' => 'integer|exists:users,id',
            'is_active'  => 'required|boolean',
        ]);

        $active  = (bool) $validated['is_active'];
        $updated = [];
        $skipped = [];

        foreach (User::whereIn('id', $validated['user_ids'])->get() as $user) {
            $result = $this->setActiveStatus($user, $active);
            if ($result['ok']) {
                $updated[] = $user->id;
            } else {
                $skipped[] = ['id' => $user->id, 'reason' => $result['message']];
            }
        }

        return response()->json([
            'message' => count($updated) . ' user(s) ' . ($active ? 'activated' : 'deactivated') . '.',
            'updated' => $updated,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Apply an active-state change with self / last-Super-Admin lockout guards.
     * Deactivation also revokes all API tokens (force logout). Returns an outcome array.
     */
    private function setActiveStatus(User $user, bool $active): array
    {
        if (Auth::id() === $user->id) {
            return ['ok' => false, 'message' => 'You cannot change your own account status.'];
        }

        if (! $active && $this->isLastSuperAdmin($user)) {
            return ['ok' => false, 'message' => 'Cannot deactivate the last Super Admin.'];
        }

        if ($user->is_active === $active) {
            return ['ok' => true]; // already in the desired state — no-op
        }

        $user->update(['is_active' => $active]);

        if (! $active) {
            $user->tokens()->delete(); // force logout
        }

        $this->auditUser($active ? 'user_activated' : 'user_deactivated', $user);

        return ['ok' => true];
    }

    /**
     * List a user's active API tokens (sessions).
     */
    public function tokens(User $user): JsonResponse
    {
        $tokens = $user->tokens()
            ->orderByDesc('last_used_at')
            ->get(['id', 'name', 'last_used_at', 'created_at']);

        return response()->json(['data' => $tokens]);
    }

    /**
     * Revoke a single token (sign out one session).
     */
    public function revokeToken(User $user, int $tokenId): JsonResponse
    {
        $deleted = $user->tokens()->where('id', $tokenId)->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Token not found for this user.'], 404);
        }

        $this->auditUser('user_token_revoked', $user, ['token_id' => $tokenId]);

        return response()->json(['message' => 'Token revoked.']);
    }

    /**
     * Revoke all of a user's tokens (force logout everywhere).
     */
    public function revokeAllTokens(User $user): JsonResponse
    {
        $count = $user->tokens()->count();
        $user->tokens()->delete();

        $this->auditUser('user_sessions_revoked', $user, ['tokens_revoked' => $count]);

        return response()->json([
            'message' => "Signed out of all sessions ({$count} token(s) revoked).",
        ]);
    }
}
