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
    /**
     * Submit an OT entry for approval.
     */
    public function submitEntry(OTEntry $entry): OTEntry
    {
        $entry->update([
            'status' => 'submitted',
            'submitted_by' => auth()->id(),
        ]);

        SystemEvent::log('submitted', 'ot_entry', $entry->id, $entry->toArray());

        $this->generateIntelligenceFlags($entry);

        return $entry;
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

        $currentBalance = $subject->ot_balance;
        $newBalance = $currentBalance + $entry->hours;

        // Get previous hash
        $previousEntryQuery = LedgerEntry::query();
        if ($entry->employee_id) {
            $previousEntryQuery->where('employee_id', $entry->employee_id);
        } else {
            $previousEntryQuery->where('technical_labour_id', $entry->technical_labour_id);
        }
        $previousEntry = $previousEntryQuery->latest('occurred_at')->first();
        $previousHash = $previousEntry ? $previousEntry->chain_hash : null;

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
        $recentHoursQuery = OTEntry::where('work_date', '>', $entry->work_date->subDays(7))
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

            if ($subject->ot_balance < $comp->hours) {
                throw new \Exception("Insufficient balance for this compensation.");
            }

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

        $currentBalance = $subject->ot_balance;
        $newBalance = $currentBalance - $comp->hours;

        // Get previous hash
        $previousEntryQuery = LedgerEntry::query();
        if ($comp->employee_id) {
            $previousEntryQuery->where('employee_id', $comp->employee_id);
        } else {
            $previousEntryQuery->where('technical_labour_id', $comp->technical_labour_id);
        }

        $previousEntry = $previousEntryQuery->latest('occurred_at')->first();
        $previousHash = $previousEntry ? $previousEntry->chain_hash : null;

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
