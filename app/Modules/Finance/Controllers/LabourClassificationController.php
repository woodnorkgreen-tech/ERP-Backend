<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Which departments' pay is a cost of delivering client work.
 *
 * Payroll used to post every shilling of gross pay to `7550 Salaries & Wages`,
 * an operating expense — so a technician building a client's stand was recorded
 * exactly like an accounts clerk, and gross margin could not be computed at all.
 * Marking a department `direct` sends its people's pay to Cost of Sales instead.
 *
 * ## Why this is a Finance screen and not an administration one
 *
 * Departments are maintained under Administration, and the obvious place for
 * this field was that form. It is not an administrative attribute though — it
 * decides which side of the gross-margin line somebody's salary falls on, which
 * is an accounting policy decision. Putting it on the department form would mean
 * whoever creates a department also decides its accounting treatment, usually
 * without being told that is what they are doing.
 *
 * Held under `finance.expense_codes.manage`, the permission that already means
 * "Finance owns the mapping from operational things to accounts". A new
 * permission would have been a truer name and a worse outcome: it would need
 * granting to somebody before this screen worked at all, and permission
 * migrations in this codebase have a history of granting to roles that do not
 * exist and failing silently.
 *
 * ## Unclassified means overhead, deliberately
 *
 * Null is treated as indirect, which is exactly what payroll did before this
 * distinction existed. So an installation that classifies nothing behaves as it
 * always has, and classifying a department is an improvement somebody opts into
 * rather than a silent restatement of months already reported.
 */
class LabourClassificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->can(Permissions::FINANCE_EXPENSE_CODES_MANAGE)
                || $request->user()?->can(Permissions::FINANCE_REPORTS_VIEW),
            403,
        );

        // The headcount is what makes the decision concrete: "Production, 11
        // people" is a judgement someone can actually make, where a bare list of
        // department names is not.
        $departments = Department::query()
            ->leftJoin('employees', function ($join) {
                $join->on('employees.department_id', '=', 'departments.id')
                    ->where('employees.status', 'active');
            })
            ->groupBy('departments.id', 'departments.name', 'departments.labour_classification')
            ->orderBy('departments.name')
            ->get([
                'departments.id',
                'departments.name',
                'departments.labour_classification',
                DB::raw('COUNT(employees.id) as active_employees'),
            ])
            ->map(fn ($department) => [
                'id' => $department->id,
                'name' => $department->name,
                'labour_classification' => $department->labour_classification ?? Department::LABOUR_INDIRECT,
                'is_explicit' => $department->labour_classification !== null,
                'active_employees' => (int) $department->active_employees,
            ]);

        return response()->json([
            'status' => 'success',
            'data' => $departments,
            'summary' => [
                'total' => $departments->count(),
                'direct' => $departments->where('labour_classification', Department::LABOUR_DIRECT)->count(),
                'unclassified' => $departments->where('is_explicit', false)->count(),
            ],
        ]);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_EXPENSE_CODES_MANAGE), 403);

        $validated = $request->validate([
            'labour_classification' => ['required', 'in:direct,indirect'],
        ]);

        $department->forceFill([
            'labour_classification' => $validated['labour_classification'],
        ])->save();

        return response()->json([
            'status' => 'success',
            'message' => $validated['labour_classification'] === Department::LABOUR_DIRECT
                ? $department->name . ' pay is now a direct cost of client work.'
                : $department->name . ' pay is now office overhead.',
            // Stated plainly because it is the commonest surprise: changing this
            // does not touch a payroll run that has already posted.
            'note' => 'This applies to payroll posted from now on. Runs already posted are unchanged.',
        ]);
    }
}
