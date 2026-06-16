<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\OTEntry;
use App\Modules\HR\Models\LedgerEntry;
use App\Modules\HR\Models\Compensation;
use App\Modules\HR\Models\OTFlag;
use App\Modules\HR\Models\SystemEvent;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class OvertimeService
{
    public function __construct(
        private readonly AttendanceOvertimeService $attendanceOvertimeService
    ) {}

    /**
     * Submit an OT entry for approval.
     */
    public function submitEntry(OTEntry $entry): OTEntry
    {
        $entry->update([
            'status' => 'submitted',
            'submitted_by' => auth()->id(),
        ]);
        $this->attendanceOvertimeService->markStatus($entry, 'submitted');

        SystemEvent::log('submitted', 'ot_entry', $entry->id, $entry->toArray());

        $this->generateIntelligenceFlags($entry);

        return $entry;
    }

    public function syncAttendanceStatus(OTEntry $entry, string $status): void
    {
        $this->attendanceOvertimeService->markStatus($entry, $status);
    }

    /**
     * Approve an OT entry (Supervisor level).
     */
    public function supervisorApprove(OTEntry $entry): OTEntry
    {
        $entry->update([
            'status' => 'under_review',
            'supervisor_approved_by' => auth()->id(),
            'supervisor_approved_at' => now(),
        ]);
        $this->attendanceOvertimeService->markStatus($entry, 'under_review');

        SystemEvent::log('supervisor_approved', 'ot_entry', $entry->id, [
            'approver' => auth()->user()->name
        ]);

        return $entry;
    }

    /**
     * Final approval (HR level).
     * This triggers the ledger credit.
     */
    public function hrApprove(OTEntry $entry): OTEntry
    {
        return DB::transaction(function () use ($entry) {
            $entry->update([
                'status' => 'approved',
                'hr_approved_by' => auth()->id(),
                'hr_approved_at' => now(),
            ]);

            $this->creditLedger($entry);

            $entry->update(['status' => 'done']);
            $this->attendanceOvertimeService->markApproved($entry);

            SystemEvent::log('hr_approved', 'ot_entry', $entry->id, [
                'approver' => auth()->user()->name
            ]);

            return $entry;
        });
    }

    /**
     * Create a credit ledger entry for approved OT.
     */
    protected function creditLedger(OTEntry $entry): LedgerEntry
    {
        $subject = $entry->employee ?? $entry->technicalLabour;

        if (!$subject) {
            throw new \Exception("Cannot process ledger: entry is not linked to any personnel.");
        }

        // Lock this subject's ledger tail so concurrent approvals serialize:
        // without this, two transactions read the same balance/hash and fork the
        // tamper-evident chain (lost update). Deterministic ordering (occurred_at
        // then id) breaks same-timestamp ties so the chain head is unambiguous.
        $previousEntryQuery = LedgerEntry::query()->lockForUpdate();
        if ($entry->employee_id) {
            $previousEntryQuery->where('employee_id', $entry->employee_id);
        } else {
            $previousEntryQuery->where('technical_labour_id', $entry->technical_labour_id);
        }
        $previousEntry = $previousEntryQuery
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
        $previousHash = $previousEntry ? $previousEntry->chain_hash : null;

        // Derive the running balance from the locked chain head, not the unlocked
        // ot_balance accessor, so the figure is consistent under concurrency.
        $currentBalance = $previousEntry ? (float) $previousEntry->balance_after : 0.0;
        $newBalance = $currentBalance + $entry->hours;

        $ledgerData = [
            'kind' => 'credit',
            'hours' => $entry->hours,
            'balance_after' => $newBalance,
            'ot_entry_id' => $entry->id,
            'source_type' => 'ot_approval',
            'source_snapshot' => [
                'project' => $entry->project?->title ?? 'N/A',
                'work_date' => $entry->work_date->toDateString(),
                'approved_by_hr' => auth()->user()->name,
                'approved_by_supervisor' => $entry->supervisorApprover?->name ?? 'N/A',
            ],
            'occurred_at' => now(),
            'note' => "Overtime approved for " . $entry->work_date->toDateString(),
        ];

        if ($entry->employee_id) {
            $ledgerData['employee_id'] = $entry->employee_id;
        } else {
            $ledgerData['technical_labour_id'] = $entry->technical_labour_id;
        }

        $ledger = new LedgerEntry($ledgerData);

        $ledger->chain_hash = LedgerEntry::generateHash($ledger, $previousHash);
        $ledger->save();

        return $ledger;
    }

    /**
     * Analyze entry for suspicious patterns and burnout.
     */
    public function generateIntelligenceFlags(OTEntry $entry): void
    {
        // 1. Fatigue/Burnout Check (15+ hours in the trailing 7 days)
        // We include the hours of the current entry in the check.
        // NOTE: use copy() — subDays() mutates the Carbon instance in place,
        // which would otherwise corrupt $entry->work_date and the second bound below.
        $windowStart = $entry->work_date->copy()->subDays(7);
        $recentHoursQuery = OTEntry::where('work_date', '>', $windowStart)
            ->where('work_date', '<=', $entry->work_date)
            ->where('id', '!=', $entry->id)
            ->whereIn('status', ['submitted', 'under_review', 'approved', 'done']);

        if ($entry->employee_id) {
            $recentHoursQuery->where('employee_id', $entry->employee_id);
        } else if ($entry->technical_labour_id) {
            $recentHoursQuery->where('technical_labour_id', $entry->technical_labour_id);
        } else {
            return;
        }
            
        $recentHours = $recentHoursQuery->sum('hours');
            
        $totalRollingHours = $recentHours + $entry->hours;

        if ($totalRollingHours > 15) {
            OTFlag::create([
                'ot_entry_id' => $entry->id,
                'type' => 'fatigue_risk',
                'severity' => 'high',
                'resolution_notes' => "Employee has logged {$totalRollingHours} hours in the trailing 7 days (Limit: 15)."
            ]);
        }

        // 2. Overlap Check (Simplified)
        $overlapQuery = OTEntry::where('id', '!=', $entry->id)
            ->where('work_date', $entry->work_date)
            ->where(function ($q) use ($entry) {
                $q->whereBetween('start_time', [$entry->start_time, $entry->end_time])
                  ->orWhereBetween('end_time', [$entry->start_time, $entry->end_time]);
            });

        if ($entry->employee_id) {
            $overlapQuery->where('employee_id', $entry->employee_id);
        } else {
            $overlapQuery->where('technical_labour_id', $entry->technical_labour_id);
        }

        $overlap = $overlapQuery->exists();

        if ($overlap) {
            OTFlag::create([
                'ot_entry_id' => $entry->id,
                'type' => 'overlapping_projects',
                'severity' => 'medium',
                'resolution_notes' => "Time overlaps with another OT entry on the same day."
            ]);
        }
    }

    /**
     * Supervisor approval for compensation request.
     */
    public function supervisorApproveCompensation(Compensation $comp): Compensation
    {
        $comp->update([
            'status' => 'under_review',
            'supervisor_approved_by' => auth()->id(),
            'supervisor_approved_at' => now(),
        ]);

        SystemEvent::log('supervisor_approved', 'compensation', $comp->id, [
            'approver' => auth()->user()->name
        ]);

        return $comp;
    }

    /**
     * HR approval for compensation request.
     */
    public function hrApproveCompensation(Compensation $comp): Compensation
    {
        return DB::transaction(function () use ($comp) {
            $subject = $comp->employee ?? $comp->technicalLabour;
            
            if (!$subject) {
                throw new \Exception("Cannot process compensation: not linked to any personnel.");
            }

            // Balance sufficiency is enforced inside debitLedger() under a row lock
            // so the check and the write are atomic; a pre-check here would be racy.

            $comp->update([
                'status' => 'approved',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            $this->debitLedger($comp);

            SystemEvent::log('approved', 'compensation', $comp->id, [
                'type' => $comp->type,
                'hours' => $comp->hours
            ]);

            return $comp;
        });
    }

    /**
     * Create a debit ledger entry for approved compensation.
     */
    protected function debitLedger(Compensation $comp): LedgerEntry
    {
        $subject = $comp->employee ?? $comp->technicalLabour;

        if (!$subject) {
            throw new \Exception("Cannot process ledger: compensation is not linked to any personnel.");
        }

        // Lock this subject's ledger tail so concurrent debits/credits serialize
        // (see creditLedger). Deterministic ordering breaks same-timestamp ties.
        $previousEntryQuery = LedgerEntry::query()->lockForUpdate();
        if ($comp->employee_id) {
            $previousEntryQuery->where('employee_id', $comp->employee_id);
        } else {
            $previousEntryQuery->where('technical_labour_id', $comp->technical_labour_id);
        }

        $previousEntry = $previousEntryQuery
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
        $previousHash = $previousEntry ? $previousEntry->chain_hash : null;

        // Derive balance from the locked chain head and enforce the overdraft
        // guard here, atomically with the write, so concurrent debits cannot both
        // pass an out-of-lock balance check and drive the balance negative.
        $currentBalance = $previousEntry ? (float) $previousEntry->balance_after : 0.0;
        if ($currentBalance < $comp->hours) {
            throw new \Exception("Insufficient balance for this compensation.");
        }
        $newBalance = $currentBalance - $comp->hours;

        $ledgerData = [
            'kind' => 'debit',
            'hours' => $comp->hours,
            'balance_after' => $newBalance,
            'compensation_id' => $comp->id,
            'source_type' => 'compensation_use',
            'source_snapshot' => [
                'type' => $comp->type,
                'comp_date' => $comp->comp_date->toDateString(),
                'approved_by' => auth()->user()->name,
            ],
            'occurred_at' => now(),
            'note' => "Compensatory " . ($comp->type === 'full_day' ? 'Full Day' : 'Half Day') . " off used on " . $comp->comp_date->toDateString(),
        ];

        if ($comp->employee_id) {
            $ledgerData['employee_id'] = $comp->employee_id;
        } else {
            $ledgerData['technical_labour_id'] = $comp->technical_labour_id;
        }

        $ledger = new LedgerEntry($ledgerData);

        $ledger->chain_hash = LedgerEntry::generateHash($ledger, $previousHash);
        $ledger->save();

        return $ledger;
    }
}
