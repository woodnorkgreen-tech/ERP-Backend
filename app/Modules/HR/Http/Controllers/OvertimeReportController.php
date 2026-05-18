<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\OTEntry;
use App\Modules\HR\Models\Compensation;
use App\Modules\HR\Models\LedgerEntry;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\TechnicalLabour;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class OvertimeReportController extends Controller
{
    /**
     * Tamper-Evident Labor Audit Trail (Ledger of Truth)
     */
    public function downloadLedgerAudit(Request $request)
    {
        $user = auth()->user();
        if (!$user->hasAnyRole(['Super Admin', 'Admin', 'HR Admin', 'HR'])) {
            abort(403, 'Unauthorized to download global ledger audit.');
        }

        $query = LedgerEntry::with(['employee.department', 'technicalLabour', 'otEntry', 'compensation'])
                            ->orderBy('occurred_at', 'desc');

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('occurred_at', [$request->start_date, $request->end_date]);
        }

        $entries = $query->get();

        $pdf = Pdf::loadView('pdf.overtime.ledger_audit', compact('entries'));
        return $pdf->download("labor-audit-ledger-" . now()->format('Ymd') . ".pdf");
    }

    /**
     * Departmental "Fatigue & Burnout" Risk Matrix
     */
    public function downloadFatigueMatrix(Request $request)
    {
        $user = auth()->user();
        if (!$user->hasAnyRole(['Super Admin', 'Admin', 'HR Admin', 'HR', 'Manager', 'Lead'])) {
            abort(403, 'Unauthorized.');
        }

        $days = $request->input('days', 30);
        $threshold = $request->input('threshold', 20); // Flag if > 20 hours OT in period

        $startDate = Carbon::now()->subDays($days);

        // Calculate OT hours per employee
        $employees = Employee::with('department')
            ->whereHas('otEntries', function($q) use ($startDate) {
                $q->whereIn('status', ['approved', 'done'])->where('work_date', '>=', $startDate);
            })
            ->withSum(['otEntries' => function($q) use ($startDate) {
                $q->whereIn('status', ['approved', 'done'])->where('work_date', '>=', $startDate);
            }], 'hours')
            ->get()
            ->filter(fn($e) => $e->ot_entries_sum_hours >= $threshold);

        // Calculate OT hours per technical labour
        $techs = TechnicalLabour::whereHas('otEntries', function($q) use ($startDate) {
                $q->whereIn('status', ['approved', 'done'])->where('work_date', '>=', $startDate);
            })
            ->withSum(['otEntries' => function($q) use ($startDate) {
                $q->whereIn('status', ['approved', 'done'])->where('work_date', '>=', $startDate);
            }], 'hours')
            ->get()
            ->filter(fn($t) => $t->ot_entries_sum_hours >= $threshold);

        $pdf = Pdf::loadView('pdf.overtime.fatigue_matrix', compact('employees', 'techs', 'days', 'threshold', 'startDate'));
        return $pdf->download("fatigue-risk-matrix-" . now()->format('Ymd') . ".pdf");
    }

    /**
     * Project-Based Labor Allocation Reports
     */
    public function downloadProjectAllocation(Request $request)
    {
        $user = auth()->user();
        if (!$user->hasAnyRole(['Super Admin', 'Admin', 'HR Admin', 'HR', 'Project Manager', 'Project Officer'])) {
            abort(403, 'Unauthorized.');
        }

        $projects = \App\Models\Project::with(['otEntries' => function($q) {
            $q->whereIn('status', ['approved', 'done'])->with(['employee', 'technicalLabour']);
        }])->whereHas('otEntries', function($q) {
            $q->whereIn('status', ['approved', 'done']);
        })->get();

        $pdf = Pdf::loadView('pdf.overtime.project_allocation', compact('projects'));
        return $pdf->download("project-labor-allocation-" . now()->format('Ymd') . ".pdf");
    }

    /**
     * Technical Pool vs Internal Staff Cost Analysis
     */
    public function downloadTechnicalPoolAnalysis(Request $request)
    {
        $user = auth()->user();
        if (!$user->hasAnyRole(['Super Admin', 'Admin', 'HR Admin', 'HR'])) {
            abort(403, 'Unauthorized.');
        }

        $internalHours = OTEntry::whereNotNull('employee_id')->whereIn('status', ['approved', 'done'])->sum('hours');
        $techHours = OTEntry::whereNotNull('technical_labour_id')->whereIn('status', ['approved', 'done'])->sum('hours');

        // Hypothetical cost calculation
        // Internal cost = hours * (salary / 160) * 1.5 
        // Tech cost = sum(hours * (day_rate / 8))
        
        $techLogs = OTEntry::whereNotNull('technical_labour_id')->whereIn('status', ['approved', 'done'])->with('technicalLabour')->get();
        $techCost = $techLogs->sum(function($log) {
            $hourlyRate = ($log->technicalLabour->day_rate ?? 0) / 8;
            return $log->hours * $hourlyRate;
        });

        $empLogs = OTEntry::whereNotNull('employee_id')->whereIn('status', ['approved', 'done'])->with('employee')->get();
        $internalCost = $empLogs->sum(function($log) {
            $hourlyRate = ($log->employee->salary ?? 0) / 160;
            return $log->hours * ($hourlyRate * 1.5);
        });

        $pdf = Pdf::loadView('pdf.overtime.technical_pool', compact('internalHours', 'techHours', 'techCost', 'internalCost'));
        return $pdf->download("technical-pool-analysis-" . now()->format('Ymd') . ".pdf");
    }

    /**
     * Personal Time-Off Statements
     */
    public function downloadPersonalStatement(Request $request, $type = 'emp', $id = null)
    {
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Admin', 'Admin', 'HR Admin', 'HR']);

        if (!$id) {
            $id = $user->employee_id;
            $type = 'emp';
        }

        if (!$isGlobal && ($type !== 'emp' || $id != $user->employee_id)) {
            abort(403, 'Unauthorized to view statement for another user.');
        }

        if ($type === 'tech') {
            $subject = TechnicalLabour::findOrFail($id);
            $ledger = LedgerEntry::where('technical_labour_id', $id)->with(['otEntry', 'compensation'])->orderBy('occurred_at', 'desc')->get();
        } else {
            $subject = Employee::findOrFail($id);
            $ledger = LedgerEntry::where('employee_id', $id)->with(['otEntry', 'compensation'])->orderBy('occurred_at', 'desc')->get();
        }

        $pdf = Pdf::loadView('pdf.overtime.personal_statement', compact('subject', 'ledger', 'type'));
        return $pdf->download("personal-time-statement-{$type}-{$id}-" . now()->format('Ymd') . ".pdf");
    }
}
