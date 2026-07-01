<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\OTEntry;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Services\OvertimeService;
use App\Constants\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OvertimeController extends Controller
{
    protected $overtimeService;

    public function __construct(OvertimeService $overtimeService)
    {
        $this->overtimeService = $overtimeService;
    }

    /**
     * List OT entries.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = OTEntry::with([
            'employee.department',
            'project',
            'technicalLabour',
            'supervisorApprover',
            'hrApprover',
            'submitter',
            'flags',
        ]);

        // Intelligent Data Isolation: Role & Hierarchy aware
        if (!$user->can(Permissions::OVERTIME_READ) && !$user->hasRole(['Super Admin', 'HR'])) {
            $employeeId = $user->employee_id;
            $accessibleDeptIds = $user->getAccessibleDepartments()->pluck('id')->toArray();
            $isDeptLead = $user->isDeptLead();

            if ($employeeId) {
                $query->where(function ($q) use ($employeeId, $accessibleDeptIds, $isDeptLead) {
                    // 1. Personal Entries
                    $q->where('employee_id', $employeeId);

                    // 2. Direct Subordinates (Manager Logic)
                    $q->orWhereHas('employee', function ($sub) use ($employeeId) {
                        $sub->where('manager_id', $employeeId);
                    });

                    // 3. Departmental Subordinates (Dept Lead Logic)
                    if ($isDeptLead && !empty($accessibleDeptIds)) {
                        $q->orWhereHas('employee', function ($sub) use ($accessibleDeptIds) {
                            $sub->whereIn('department_id', $accessibleDeptIds);
                        });

                        // 4. Technical Labour (regarded under Production department)
                        $productionDept = Department::where('name', 'Production')->first();
                        if ($productionDept && in_array($productionDept->id, $accessibleDeptIds)) {
                            $q->orWhereNotNull('technical_labour_id');
                        }
                    }
                });
            } else {
                return response()->json([]);
            }
        }

        // Filtering
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->has('department_id')) {
            $query->whereHas('employee', function($q) use ($request) {
                $q->where('department_id', $request->department_id);
            });
        }

        return response()->json($query->latest()->get());
    }

    /**
     * Store a new OT entry (Single).
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        
        $validated = $request->validate([
            'employee_id' => 'nullable|exists:employees,id',
            'technical_labour_id' => 'nullable|exists:technical_labours,id',
            'project_id' => 'nullable|exists:project_enquiries,id',
            'job_title' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'work_date' => 'required|date',
            'start_time' => 'required',
            'end_time' => 'required',
            'notes' => 'nullable|string',
        ]);

        $employeeId = $validated['employee_id'] ?? null;
        $techId = $validated['technical_labour_id'] ?? null;

        if (!$employeeId && !$techId && $user->employee_id) {
            $employeeId = $user->employee_id;
        }

        if (!$employeeId && !$techId) {
            return response()->json(['message' => 'No personnel specified.'], 422);
        }

        // Security Validation
        $isGlobal = $user->hasRole(['Super Admin', 'Admin', 'HR', 'Project Officer', 'Project Manager', 'Production']);
        $isOwn = $employeeId && ($employeeId == $user->employee_id);
        
        if (!$isGlobal && !$isOwn) {
            if ($employeeId) {
                $employee = Employee::find($employeeId);
                $isManager = $employee->manager_id === $user->employee_id;
                $isDeptLead = $employee->department?->manager_id === $user->employee_id;
                if (!$isManager && !$isDeptLead) abort(403, 'Unauthorized.');
            } else {
                // For Technical Labour, regarded under Production department
                $productionDept = Department::where('name', 'Production')->first();
                $isProductionLead = $productionDept && ($productionDept->manager_id === $user->employee_id);
                if (!$isProductionLead) abort(403, 'Unauthorized to record for technical labour.');
            }
        }

        // Calculate hours with Midnight Awareness
        $start = \Carbon\Carbon::parse($validated['start_time']);
        $end = \Carbon\Carbon::parse($validated['end_time']);
        if ($end->lessThan($start)) $end->addDay();
        $hours = $start->diffInMinutes($end) / 60;

        if ($hours <= 0) return response()->json(['message' => 'Invalid time range'], 422);

        $entry = OTEntry::create(array_merge($validated, [
            'employee_id' => $employeeId,
            'technical_labour_id' => $techId,
            'hours' => $hours,
            'status' => 'draft',
            'submitted_by' => $user->id,
        ]));

        return response()->json($entry, 201);
    }

    /**
     * Bulk store OT entries for multiple employees.
     */
    public function bulkStore(Request $request)
    {
        $user = auth()->user();
        
        // Permission Check: HR, Admins, Managers, Dept Leads, and Project Officers can do bulk
        if (!$user->hasRole(['Super Admin', 'Admin', 'HR', 'Project Officer', 'Project Manager', 'Production']) && !$user->isManager() && !$user->isDeptLead()) {
            abort(403, 'Unauthorized to perform bulk overtime recording.');
        }

        $validated = $request->validate([
            'employee_ids'          => 'nullable|array',
            'employee_ids.*'        => 'exists:employees,id',
            'technical_labour_ids'  => 'nullable|array',
            'technical_labour_ids.*'=> 'exists:technical_labours,id',
            'project_id'            => 'nullable|exists:project_enquiries,id',
            'job_title'             => 'nullable|string|max:255',
            'location'              => 'nullable|string|max:255',
            'work_date'             => 'required|date',
            'start_time'            => 'required',
            'end_time'              => 'required',
            'notes'                 => 'nullable|string',
        ]);

        // Calculate hours
        $start = \Carbon\Carbon::parse($validated['start_time']);
        $end   = \Carbon\Carbon::parse($validated['end_time']);
        if ($end->lessThan($start)) $end->addDay();
        $hours = $start->diffInMinutes($end) / 60;

        if ($hours <= 0) return response()->json(['message' => 'Invalid time range'], 422);

        $entries = [];
        $isGlobal = $user->hasRole(['Super Admin', 'Admin', 'HR', 'Project Officer', 'Project Manager', 'Production']);
        $sharedData = array_merge($request->only(['project_id', 'job_title', 'location', 'work_date', 'start_time', 'end_time', 'notes']), [
            'hours'        => $hours,
            'status'       => 'submitted',
            'submitted_by' => $user->id,
        ]);

        // Process Employees
        if (!empty($validated['employee_ids'])) {
            foreach ($validated['employee_ids'] as $empId) {
                if (!$isGlobal) {
                    $employee = Employee::find($empId);
                    // Dept Lead / Manager can only log their own department members
                    if ($employee->manager_id !== $user->employee_id &&
                        $employee->department?->manager_id !== $user->employee_id) {
                        continue;
                    }
                }
                $entry = OTEntry::create(array_merge($sharedData, ['employee_id' => $empId]));
                // Bulk entries skip submitEntry(), so run the fatigue/overlap checks here too —
                // otherwise bulk-logged overtime would never raise safety flags.
                $this->overtimeService->generateIntelligenceFlags($entry);
                $entries[] = $entry;
            }
        }

        // Process Technical Labour — Dept Leads can now also log tech labour
        if (!empty($validated['technical_labour_ids'])) {
            $productionDept = Department::where('name', 'Production')->first();
            $isProductionLead = $productionDept && ($productionDept->manager_id === $user->employee_id);

            if ($isGlobal || $isProductionLead) {
                foreach ($validated['technical_labour_ids'] as $techId) {
                    $entry = OTEntry::create(array_merge($sharedData, ['technical_labour_id' => $techId]));
                    $this->overtimeService->generateIntelligenceFlags($entry);
                    $entries[] = $entry;
                }
            } else {
                abort(403, 'Unauthorized to record overtime for technical labour.');
            }
        }

        \App\Modules\HR\Models\SystemEvent::log('bulk_submitted', 'ot_entry', 0, [
            'actor'   => $user->name,
            'count'   => count($entries),
            'date'    => $validated['work_date'],
        ]);

        return response()->json([
            'message' => 'Bulk overtime submitted for approval',
            'count'   => count($entries),
            'data'    => $entries,
        ], 201);
    }

    /**
     * Submit an entry for approval.
     */
    public function submit(OTEntry $entry)
    {
        $this->overtimeService->submitEntry($entry);
        return response()->json($entry);
    }

    /**
     * Supervisor approval.
     */
    public function supervisorApprove(OTEntry $entry)
    {
        if (Gate::denies('supervisorApprove', $entry)) {
            abort(403, 'Governance Gate Locked: you are not authorized to approve this overtime entry, or it must be validated by HR.');
        }

        $this->overtimeService->supervisorApprove($entry);
        return response()->json($entry);
    }

    /**
     * HR approval (Final).
     */
    public function hrApprove(OTEntry $entry)
    {
        // Authorization (HR-approval permission/role + segregation of duties) lives in
        // OvertimePolicy::hrApprove.
        if (Gate::denies('hrApprove', $entry)) {
            abort(403, 'Governance Gate Locked: only HR may give final approval, and never to their own overtime.');
        }

        $this->overtimeService->hrApprove($entry);
        return response()->json($entry);
    }

    /**
     * Reject an entry.
     */
    public function reject(Request $request, OTEntry $entry)
    {
        $user = auth()->user();

        if (Gate::denies('reject', $entry)) {
            abort(403, 'Governance Gate Locked: Unauthorized to reject this specific mission log.');
        }

        // A credited entry is settled on the tamper-evident ledger and can't simply be
        // rejected — that would leave the credited hours stranded. Reverse it instead.
        if ($entry->status === 'done') {
            return response()->json([
                'message' => 'This entry is already credited to the ledger and cannot be rejected. Post a reversal instead.',
            ], 422);
        }

        $request->validate(['reason' => 'required|string']);

        $entry->update([
            'status' => 'rejected',
            'rejected_reason' => $request->reason,
        ]);
        $this->overtimeService->syncAttendanceStatus($entry, 'rejected');

        \App\Modules\HR\Models\SystemEvent::log('rejected', 'ot_entry', $entry->id, [
            'reason' => $request->reason,
            'actor' => $user->name
        ]);

        return response()->json($entry);
    }

    /**
     * Re-open a rejected entry.
     */
    public function reopen(OTEntry $entry)
    {
        if ($entry->status !== 'rejected') {
            return response()->json(['message' => 'Only rejected entries can be re-opened'], 422);
        }

        $user = auth()->user();

        if (Gate::denies('reopen', $entry)) {
            abort(403, 'Governance Gate Locked: Unauthorized to re-open this specific overtime entry.');
        }

        $entry->update([
            'status' => 'submitted',
            'rejected_reason' => null,
        ]);
        $this->overtimeService->syncAttendanceStatus($entry, 'submitted');

        \App\Modules\HR\Models\SystemEvent::log('reopened', 'ot_entry', $entry->id, [
            'actor' => $user->name
        ]);

        return response()->json($entry);
    }

    /**
     * Get ledger index (Global for Admin/HR, Personal for others).
     */
    public function ledger(Request $request)
    {
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Admin', 'Admin', 'HR']);

        $query = \App\Modules\HR\Models\LedgerEntry::with([
            'employee.department',
            'technicalLabour',
            'otEntry.submitter',
            'compensation',
            'reversal',   // the compensating entry that cancels this one, if any
            'reverses',   // the original this row reverses, if this is a reversal
        ]);
        
        if (!$isGlobal) {
            $employeeId = $user->employee_id;
            if (!$employeeId) {
                return response()->json([]);
            }
            $query->where('employee_id', $employeeId);
        }

        return response()->json($query->latest('occurred_at')->get());
    }

    /**
     * Get balance and ledger for an employee or technical labour.
     */
    public function balance($type, $id)
    {
        try {
            if ($type === 'tech') {
                $subject = \App\Modules\HR\Models\TechnicalLabour::findOrFail($id);
            } else {
                $subject = \App\Modules\HR\Models\Employee::findOrFail($id);
            }
            $ledger = $subject->ledgerEntries()->with(['otEntry', 'compensation', 'reversal', 'reverses'])->latest()->get();
        } catch (\Exception $e) {
            $ledger = [];
            $subject = null;
        }

        return response()->json([
            'balance' => $subject ? $subject->ot_balance : 0,
            'ledger' => $ledger,
        ]);
    }

    /**
     * Get active projects assigned to the current user.
     */
    public function projects()
    {
        // Find projects
        $projects = \App\Models\Project::with('enquiry')
            ->where('status', '!=', 'completed')
            ->latest()
            ->get()
            ->map(function ($project) {
                $enquiry = $project->enquiry;
                $title = $enquiry?->title ?? "Project #{$project->project_id}";
                $jobNumber = $enquiry?->job_number;
                $displayTitle = $jobNumber ? "{$jobNumber} - {$title}" : $title;

                return [
                    'id' => $project->enquiry_id,
                    'title' => $displayTitle,
                    'location' => $enquiry?->venue,
                ];
            })
            ->values();

        return response()->json($projects);
    }

    /**
     * Reverse a settled ledger entry by posting a compensating entry. The original is
     * preserved — reversal is the only sanctioned way to unwind credited/used hours.
     */
    public function reverseLedger(Request $request, \App\Modules\HR\Models\LedgerEntry $ledgerEntry)
    {
        $user = auth()->user();

        if (!$user->hasRole(['Super Admin', 'Admin', 'HR'])) {
            abort(403, 'Governance Gate Locked: Only HR or System Administrators can reverse ledger transactions.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:500',
        ]);

        $reversal = $this->overtimeService->reverse($ledgerEntry, $validated['reason']);

        return response()->json([
            'message'  => 'Ledger entry reversed successfully.',
            'reversal' => $reversal->load(['employee', 'technicalLabour', 'reverses']),
        ], 201);
    }

    public function destroy($id)
    {
        $entry = \App\Modules\HR\Models\OTEntry::findOrFail($id);

        // Hard delete is reserved for Super Admin (OvertimePolicy::delete).
        if (Gate::denies('delete', $entry)) {
            abort(403, 'Unauthorized. Only Super Admin can delete transactions.');
        }

        // Deleting a credited entry would silently desync the running balance from the
        // ledger chain. Credited hours must be unwound through a reversal, not a delete.
        if ($entry->status === 'done') {
            return response()->json([
                'message' => 'This entry is already credited to the ledger and cannot be deleted. Post a reversal instead.',
            ], 422);
        }

        $entry->delete(); // Soft delete

        return response()->json(['message' => 'Transaction deleted successfully.']);
    }

    public function resetSystem()
    {
        $user = auth()->user();
        if (!$user->hasRole(['Super Admin'])) {
            abort(403, 'Unauthorized. Only Super Admin can reset the system.');
        }

        \Illuminate\Support\Facades\DB::transaction(function () {
            \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            \Illuminate\Support\Facades\DB::table('ot_entries')->truncate();
            \Illuminate\Support\Facades\DB::table('compensations')->truncate();
            \Illuminate\Support\Facades\DB::table('ledger_entries')->truncate();
            \Illuminate\Support\Facades\DB::table('ot_flags')->truncate();
            \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        });

        return response()->json(['message' => 'Overtime system completely reset.']);
    }
}
