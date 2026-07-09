<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\OffboardingCase;
use App\Modules\HR\Models\OffboardingCard;
use App\Modules\HR\Models\OffboardingTask;
use App\Modules\HR\Models\OffboardingAssetReturn;
use App\Modules\HR\Models\OffboardingClearance;
use App\Modules\HR\Models\OffboardingExitInterview;
use App\Modules\HR\Models\OffboardingFinalSettlement;
use App\Modules\HR\Models\OffboardingActivityLog;
use App\Modules\HR\Models\OffboardingAttachment;
use App\Modules\HR\Models\Employee;
use App\Constants\Permissions;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class OffboardingService
{
    private const CARD_CONFIG = [
        'exit_interview'   => ['title' => 'Exit Interview',    'seq' => 1, 'locked' => false],
        'asset_return'     => ['title' => 'Asset Return',      'seq' => 2, 'locked' => false],
        'clearance'        => ['title' => 'Department Clearance', 'seq' => 3, 'locked' => false],
        'final_settlement' => ['title' => 'Final Settlement',  'seq' => 4, 'locked' => true],
        'documentation'    => ['title' => 'Documentation',     'seq' => 5, 'locked' => false],
    ];

    private const ASSET_RETURN_DEFAULTS = [
        'Laptop', 'Company ID Card', 'Access Card / Keys', 'Company Phone', 'Uniform / PPE',
    ];

    private const CLEARANCE_DEFAULTS = [
        ['key' => 'it_clearance',        'label' => 'IT Clearance',                 'department_tag' => 'IT'],
        ['key' => 'finance_clearance',    'label' => 'Finance Clearance',            'department_tag' => 'Finance'],
        ['key' => 'facilities_clearance', 'label' => 'Facilities / Admin Clearance', 'department_tag' => 'Facilities'],
        ['key' => 'line_manager_clearance','label' => 'Line Manager Clearance',      'department_tag' => 'Line Manager'],
    ];

    private const DOCUMENTATION_TASK_TEMPLATES = [
        ['code' => 'DOC_CERT_OF_SERVICE', 'title' => 'Issue Certificate of Service',            'seq' => 1],
        ['code' => 'DOC_FINAL_PAYSLIP',   'title' => 'Process Final Payslip',                   'seq' => 2],
        ['code' => 'DOC_UPDATE_RECORDS',  'title' => 'Update HR Records & Org Chart',            'seq' => 3],
        ['code' => 'DOC_PAYROLL_NOTICE',  'title' => 'Notify Payroll of Separation',             'seq' => 4],
        ['code' => 'DOC_REVOKE_ACCESS',   'title' => 'Deactivate Biometric / System Access',     'seq' => 5],
    ];

    public function getEligibleEmployees(): Collection
    {
        $activeCaseEmployeeIds = OffboardingCase::whereNotIn('status', ['cancelled', 'completed'])
            ->pluck('employee_id');

        return Employee::where('status', 'active')
            ->whereNotIn('id', $activeCaseEmployeeIds)
            ->with('department')
            ->orderBy('first_name')
            ->get();
    }

    public function initiateOffboarding(array $data): OffboardingCase
    {
        $case = DB::transaction(function () use ($data) {
            $employee = Employee::findOrFail($data['employee_id']);

            $case = OffboardingCase::create([
                'employee_id'        => $employee->id,
                'initiated_by'       => Auth::id(),
                'status'             => 'initiated',
                'termination_type'   => $data['termination_type'] ?? null,
                'termination_reason' => $data['termination_reason'] ?? null,
                'last_working_day'   => $data['last_working_day'] ?? null,
                'department_id'      => $employee->department_id,
                'position'           => $employee->position,
                'notes'              => $data['notes'] ?? null,
            ]);

            $this->seedCards($case);
            $this->seedAssetReturns($case);
            $this->seedClearances($case);

            $this->log($case->id, 'offboarding_initiated', 'Offboarding initiated for ' . $employee->first_name . ' ' . $employee->last_name);

            return $case->load(['employee.department', 'cards.tasks', 'assetReturns', 'clearances']);
        });

        $this->notifyOffboarding($case, 'offboarding_started', 'Offboarding started',
            "Offboarding has started for {$case->employee->name}.", Permissions::OFFBOARDING_MANAGE);

        return $case;
    }

    public function completeTaskById(int $taskId, ?string $notes = null): OffboardingTask
    {
        $task = OffboardingTask::findOrFail($taskId);
        $this->openCase($task->offboarding_case_id);

        $task->update([
            'status'       => 'completed',
            'completed_at' => now(),
            'completed_by' => Auth::id(),
            'notes'        => $notes ?? $task->notes,
        ]);

        $this->recalculateProgress($task->offboarding_case_id);
        $this->log($task->offboarding_case_id, 'task_completed', "Task completed: {$task->title}");

        return $task->fresh();
    }

    public function reopenTaskById(int $taskId): OffboardingTask
    {
        $task = OffboardingTask::findOrFail($taskId);
        $this->openCase($task->offboarding_case_id);

        $task->update([
            'status'       => 'pending',
            'completed_at' => null,
            'completed_by' => null,
        ]);

        $this->recalculateProgress($task->offboarding_case_id);
        $this->log($task->offboarding_case_id, 'task_reopened', "Task reopened: {$task->title}");

        return $task->fresh();
    }

    public function toggleOptionalTask(int $taskId, bool $isActive): OffboardingTask
    {
        $task = OffboardingTask::findOrFail($taskId);
        $this->openCase($task->offboarding_case_id);
        abort_if(!$task->is_optional, 422, 'Only optional tasks can be toggled.');

        $task->update(['is_active' => $isActive]);
        $this->recalculateProgress($task->offboarding_case_id);

        return $task;
    }

    public function createTask(int $cardId, array $fields): OffboardingTask
    {
        $card = OffboardingCard::findOrFail($cardId);
        $this->openCase($card->offboarding_case_id);
        $isOptional = array_key_exists('is_optional', $fields) && (bool)$fields['is_optional'];

        $slug = preg_replace('/[^A-Z0-9]+/', '_', strtoupper($fields['title']));
        $slug = trim($slug, '_');
        $code = $slug ?: 'CUSTOM_TASK';
        $baseCode = $code;
        $suffix = 1;
        while (OffboardingTask::where('offboarding_case_id', $card->offboarding_case_id)->where('task_code', $code)->exists()) {
            $code = $baseCode . '_' . $suffix++;
        }

        $highestOrder = OffboardingTask::where('card_id', $cardId)->max('sequence_order') ?? 0;

        $task = OffboardingTask::create([
            'offboarding_case_id' => $card->offboarding_case_id,
            'card_id'             => $cardId,
            'task_code'           => $code,
            'title'               => $fields['title'],
            'description'         => $fields['description'] ?? null,
            'assignee_role'       => $fields['assignee_role'] ?? null,
            'is_required'         => !$isOptional,
            'is_optional'         => $isOptional,
            'is_active'           => true,
            'is_applicable'       => true,
            'is_needed'           => true,
            'sequence_order'      => $highestOrder + 1,
            'status'              => 'pending',
        ]);

        $this->recalculateProgress($card->offboarding_case_id);
        $this->log($card->offboarding_case_id, 'task_created', "Custom task created: {$task->title}");

        return $task;
    }

    public function updateTaskFlags(int $taskId, array $fields): OffboardingTask
    {
        $task = OffboardingTask::findOrFail($taskId);
        $this->openCase($task->offboarding_case_id);
        $updateData = [];

        if (array_key_exists('is_applicable', $fields)) {
            $updateData['is_applicable'] = (bool)$fields['is_applicable'];
        }

        if (array_key_exists('is_needed', $fields)) {
            $updateData['is_needed'] = (bool)$fields['is_needed'];
        }

        if (!empty($updateData)) {
            $task->update($updateData);
            $this->recalculateProgress($task->offboarding_case_id);
        }

        return $task;
    }

    public function createAssetReturnItem(int $caseId, array $fields): OffboardingAssetReturn
    {
        $this->openCase($caseId);

        $item = OffboardingAssetReturn::create([
            'offboarding_case_id' => $caseId,
            'item_name'           => $fields['item_name'],
            'is_returned'         => false,
            'is_applicable'       => true,
            'is_needed'           => true,
        ]);

        $this->log($caseId, 'asset_return_item_created', "Custom asset return item created: {$item->item_name}");
        $this->recalculateProgress($caseId);

        return $item;
    }

    public function toggleAssetReturn(int $itemId, ?string $condition = null): OffboardingAssetReturn
    {
        $item = OffboardingAssetReturn::findOrFail($itemId);
        $this->openCase($item->offboarding_case_id);
        abort_if(!$item->is_applicable || !$item->is_needed, 422, 'Item is not applicable.');

        $returning = !$item->is_returned;

        $item->update([
            'is_returned'  => $returning,
            'condition'    => $returning ? ($condition ?? $item->condition) : null,
            'received_by'  => $returning ? Auth::id() : null,
            'returned_at'  => $returning ? now() : null,
        ]);

        $this->recalculateProgress($item->offboarding_case_id);
        $this->log($item->offboarding_case_id, 'asset_return_updated', "Asset '{$item->item_name}' marked " . ($returning ? 'returned' : 'not returned'));

        return $item;
    }

    public function updateAssetReturn(int $itemId, array $fields): OffboardingAssetReturn
    {
        $item = OffboardingAssetReturn::findOrFail($itemId);
        $this->openCase($item->offboarding_case_id);
        $updateData = [];

        if (array_key_exists('is_applicable', $fields)) {
            $updateData['is_applicable'] = (bool)$fields['is_applicable'];
        }

        if (array_key_exists('is_needed', $fields)) {
            $updateData['is_needed'] = (bool)$fields['is_needed'];
        }

        if (array_key_exists('notes', $fields)) {
            $updateData['notes'] = $fields['notes'];
        }

        if (!empty($updateData)) {
            $item->update($updateData);
            $this->recalculateProgress($item->offboarding_case_id);
        }

        return $item;
    }

    public function createClearance(int $caseId, array $fields): OffboardingClearance
    {
        $this->openCase($caseId);

        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($fields['label']));
        $slug = trim($slug, '_');
        $key = $slug ?: 'custom_clearance';
        $baseKey = $key;
        $suffix = 1;
        while (OffboardingClearance::where('offboarding_case_id', $caseId)->where('clearance_key', $key)->exists()) {
            $key = $baseKey . '_' . $suffix++;
        }

        $clearance = OffboardingClearance::create([
            'offboarding_case_id' => $caseId,
            'clearance_key'       => $key,
            'label'               => $fields['label'],
            'department_tag'      => $fields['department_tag'] ?? null,
            'is_applicable'       => true,
            'is_needed'           => true,
            'status'              => 'pending',
        ]);

        $this->log($caseId, 'clearance_created', "Custom clearance created: {$clearance->label}");
        $this->recalculateProgress($caseId);

        return $clearance;
    }

    public function updateClearanceStatus(int $clearanceId, string $status, ?string $flagReason = null): OffboardingClearance
    {
        $clearance = OffboardingClearance::findOrFail($clearanceId);
        $this->openCase($clearance->offboarding_case_id);

        $updateData = ['status' => $status];

        if ($status === 'cleared') {
            $updateData['cleared_by'] = Auth::id();
            $updateData['cleared_at'] = now();
            $updateData['flag_reason'] = null;
        }

        if ($status === 'flagged') {
            $updateData['flag_reason'] = $flagReason;
        }

        if ($status === 'pending') {
            $updateData['cleared_by'] = null;
            $updateData['cleared_at'] = null;
            $updateData['flag_reason'] = null;
        }

        $clearance->update($updateData);
        $this->log($clearance->offboarding_case_id, 'clearance_updated', "Clearance '{$clearance->label}' → {$status}");
        $this->recalculateProgress($clearance->offboarding_case_id);

        $case = $clearance->offboardingCase;
        $type = $status === 'flagged' ? 'offboarding_clearance_flagged' : 'offboarding_clearance_updated';
        $title = $status === 'flagged' ? 'Offboarding clearance requires attention' : 'Offboarding clearance updated';
        $message = "{$clearance->label} for {$case->employee->name} was marked {$status}.";
        if ($status === 'flagged' && $flagReason) {
            $message .= " Reason: {$flagReason}";
        }
        $this->notifyOffboarding($case, $type, $title, $message,
            $status === 'flagged' ? Permissions::OFFBOARDING_MANAGE : null);

        return $clearance;
    }

    public function updateClearanceFlags(int $clearanceId, array $fields): OffboardingClearance
    {
        $clearance = OffboardingClearance::findOrFail($clearanceId);
        $this->openCase($clearance->offboarding_case_id);
        $updateData = [];

        if (array_key_exists('is_applicable', $fields)) {
            $updateData['is_applicable'] = (bool)$fields['is_applicable'];
        }

        if (array_key_exists('is_needed', $fields)) {
            $updateData['is_needed'] = (bool)$fields['is_needed'];
        }

        if (!empty($updateData)) {
            $clearance->update($updateData);
            $this->recalculateProgress($clearance->offboarding_case_id);
        }

        return $clearance;
    }

    public function recordExitInterview(int $caseId, array $data): OffboardingExitInterview
    {
        $this->openCase($caseId);

        $status = $data['declined'] ?? false ? 'declined' : 'completed';

        $interview = OffboardingExitInterview::updateOrCreate(
            ['offboarding_case_id' => $caseId],
            [
                'conducted_by'        => Auth::id(),
                'conducted_at'        => $data['conducted_at'] ?? now()->toDateString(),
                'reason_for_leaving'  => $data['reason_for_leaving'] ?? null,
                'feedback'            => $data['feedback'] ?? null,
                'would_recommend'     => $data['would_recommend'] ?? null,
                'rating'              => $data['rating'] ?? null,
                'status'              => $status,
                'declined_reason'     => $data['declined_reason'] ?? null,
            ]
        );

        $this->recalculateProgress($caseId);
        $this->log($caseId, 'exit_interview_recorded', 'Exit interview ' . ($status === 'declined' ? 'declined by employee' : 'recorded'));

        $case = OffboardingCase::findOrFail($caseId);
        $this->notifyOffboarding($case, 'offboarding_exit_interview_recorded', 'Exit interview recorded',
            "The exit interview for {$case->employee->name} was {$status}.");

        return $interview;
    }

    public function updateFinalSettlement(int $caseId, array $data): OffboardingFinalSettlement
    {
        $this->openCase($caseId);
        $this->assertSettlementUnlocked($caseId);

        $accruedLeavePayout = (float)($data['leave_payout_amount'] ?? 0);
        $otherDues          = (float)($data['other_dues'] ?? 0);
        $outstandingSalary  = (float)($data['outstanding_salary'] ?? 0);
        $deductions         = (float)($data['deductions'] ?? 0);

        $settlement = OffboardingFinalSettlement::updateOrCreate(
            ['offboarding_case_id' => $caseId],
            [
                'accrued_leave_days'  => $data['accrued_leave_days'] ?? null,
                'leave_payout_amount' => $data['leave_payout_amount'] ?? null,
                'outstanding_salary'  => $data['outstanding_salary'] ?? null,
                'other_dues'          => $data['other_dues'] ?? null,
                'deductions'          => $data['deductions'] ?? null,
                'net_amount'          => $accruedLeavePayout + $otherDues + $outstandingSalary - $deductions,
                'status'              => 'calculated',
                'calculated_by'       => Auth::id(),
                'calculated_at'       => now(),
                'notes'               => $data['notes'] ?? null,
            ]
        );

        $this->recalculateProgress($caseId);
        $this->log($caseId, 'settlement_calculated', 'Final settlement calculated: net amount ' . $settlement->net_amount);

        $case = OffboardingCase::findOrFail($caseId);
        $this->notifyOffboarding($case, 'offboarding_settlement_calculated', 'Final settlement ready for approval',
            "The final settlement for {$case->employee->name} has been calculated and requires approval.",
            Permissions::OFFBOARDING_SETTLEMENT);

        return $settlement;
    }

    public function approveFinalSettlement(int $caseId): OffboardingFinalSettlement
    {
        $this->openCase($caseId);
        $this->assertSettlementUnlocked($caseId);

        $settlement = OffboardingFinalSettlement::where('offboarding_case_id', $caseId)->firstOrFail();
        abort_unless($settlement->status === 'calculated', 422, 'Settlement must be calculated before it can be approved.');

        $settlement->update([
            'status'      => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        $this->recalculateProgress($caseId);
        $this->log($caseId, 'settlement_approved', 'Final settlement approved');

        $case = OffboardingCase::findOrFail($caseId);
        $this->notifyOffboarding($case, 'offboarding_settlement_approved', 'Final settlement approved',
            "The final settlement for {$case->employee->name} has been approved.");

        return $settlement;
    }

    public function markSettlementPaid(int $caseId): OffboardingFinalSettlement
    {
        $settlement = OffboardingFinalSettlement::where('offboarding_case_id', $caseId)->firstOrFail();
        abort_unless($settlement->status === 'approved', 422, 'Settlement must be approved before it can be marked as paid.');

        $settlement->update([
            'status'  => 'paid',
            'paid_at' => now(),
        ]);

        $this->recalculateProgress($caseId);
        $this->log($caseId, 'settlement_paid', 'Final settlement marked as paid');

        $case = OffboardingCase::findOrFail($caseId);
        $this->notifyOffboarding($case, 'offboarding_settlement_paid', 'Final settlement paid',
            "The final settlement for {$case->employee->name} has been marked as paid.");

        return $settlement;
    }

    public function uploadAttachments(int $caseId, string $category, ?int $relatedId, array $files): Collection
    {
        $this->openCase($caseId);
        $this->assertAttachmentTarget($caseId, $category, $relatedId);

        $uploaded = new Collection(collect($files)->map(function ($file) use ($caseId, $category, $relatedId) {
            $path = $file->store("hr/offboarding/{$caseId}/{$category}", 'public');

            return OffboardingAttachment::create([
                'offboarding_case_id' => $caseId,
                'category'            => $category,
                'related_id'          => $relatedId,
                'file_path'           => $path,
                'file_name'           => $file->getClientOriginalName(),
                'file_size'           => $file->getSize(),
                'mime_type'           => $file->getMimeType(),
                'uploaded_by'         => Auth::id(),
            ]);
        })->all());

        $this->log($caseId, 'attachment_uploaded', ucfirst(str_replace('_', ' ', $category)) . ' attachment(s) added: ' . $uploaded->pluck('file_name')->join(', '));

        $uploaded->load('uploader');

        return $uploaded;
    }

    public function deleteAttachment(int $attachmentId): void
    {
        $attachment = OffboardingAttachment::findOrFail($attachmentId);
        $this->openCase($attachment->offboarding_case_id);

        Storage::disk('public')->delete($attachment->file_path);
        $this->log($attachment->offboarding_case_id, 'attachment_deleted', "Attachment removed: {$attachment->file_name}");
        $attachment->delete();
    }

    public function approveFinalGate(int $caseId, ?string $notes = null): array
    {
        // Keep the employee relation for the completion notification; the final gate
        // soft-deletes the employee, so a freshly loaded case no longer resolves it.
        $notificationCase = OffboardingCase::with(['employee.user', 'initiatedBy'])->findOrFail($caseId);

        $result = DB::transaction(function () use ($caseId, $notes) {
            $case = OffboardingCase::with(['employee.user', 'exitInterview', 'finalSettlement', 'cards'])->findOrFail($caseId);

            abort_if($case->status === 'completed', 422, 'This offboarding case is already completed.');
            abort_if($case->status === 'cancelled', 422, 'This offboarding case was cancelled.');

            $exitInterviewDone = $case->exitInterview && in_array($case->exitInterview->status, ['completed', 'declined'], true);
            $settlementReady   = $case->finalSettlement && in_array($case->finalSettlement->status, ['approved', 'paid'], true);
            $cardsComplete     = $case->cards->whereIn('card_type', ['asset_return', 'clearance', 'documentation'])
                ->every(fn ($card) => (float)$card->progress >= 100);

            abort_unless($exitInterviewDone, 422, 'Exit interview must be completed or declined before final approval.');
            abort_unless($settlementReady, 422, 'Final settlement must be approved before final approval.');
            abort_unless($cardsComplete, 422, 'Asset return, clearance, and documentation must be fully complete before final approval.');

            $employee = $case->employee;

            $case->update([
                'status'         => 'completed',
                'hr_approved_at' => now(),
                'hr_approved_by' => Auth::id(),
                'completed_at'   => now(),
                'notes'          => $notes ?? $case->notes,
            ]);

            $employee->update([
                'status'             => 'terminated',
                'termination_reason' => $case->termination_reason,
                'termination_type'   => $case->termination_type,
                'termination_date'   => $case->last_working_day ?? now()->toDateString(),
            ]);

            $employee->delete(); // soft delete — deleted_at is set; record is retained

            $this->log($caseId, 'offboarding_completed_employee_terminated', 'Offboarding completed — employee terminated and archived');

            $warnings = [];
            if ($employee->user && $employee->user->is_active) {
                $warnings[] = 'The employee\'s user account is still active. Deactivate it via User Management to revoke system access.';
            }

            return [
                'case'     => $case->fresh(['employee', 'exitInterview', 'finalSettlement', 'cards.tasks', 'assetReturns', 'clearances']),
                'warnings' => $warnings,
            ];
        });

        $this->notifyOffboarding($notificationCase, 'offboarding_completed', 'Offboarding completed',
            "Offboarding for {$notificationCase->employee->name} is complete.");

        return $result;
    }

    public function cancelOffboarding(int $caseId, ?string $reason = null): OffboardingCase
    {
        $case = OffboardingCase::findOrFail($caseId);
        abort_if($case->status === 'completed', 422, 'A completed offboarding case cannot be cancelled — the employee has already been terminated.');
        abort_if($case->status === 'cancelled', 422, 'This offboarding case is already cancelled.');

        $case->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
            'notes'        => $reason ?? $case->notes,
        ]);

        $this->log($caseId, 'offboarding_cancelled', 'Offboarding cancelled' . ($reason ? ": {$reason}" : ''));

        $this->notifyOffboarding($case, 'offboarding_cancelled', 'Offboarding cancelled',
            "Offboarding for {$case->employee->name} was cancelled.");

        return $case;
    }

    public function recalculateProgress(int $caseId): void
    {
        $cards = OffboardingCard::where('offboarding_case_id', $caseId)->get();

        $totalCompleted = 0;
        $totalActive    = 0;

        foreach ($cards as $card) {
            if ($card->is_locked) {
                $card->update(['progress' => 0]);
                continue;
            }

            if ($card->card_type === 'asset_return') {
                $items = OffboardingAssetReturn::where('offboarding_case_id', $caseId)
                    ->where('is_applicable', true)
                    ->where('is_needed', true)
                    ->get();

                $cardTotal     = $items->count();
                $cardCompleted = $items->where('is_returned', true)->count();
            } elseif ($card->card_type === 'clearance') {
                $clearances = OffboardingClearance::where('offboarding_case_id', $caseId)
                    ->where('is_applicable', true)
                    ->where('is_needed', true)
                    ->get();

                $cardTotal     = $clearances->count();
                $cardCompleted = $clearances->where('status', 'cleared')->count();
            } elseif ($card->card_type === 'exit_interview') {
                $interview     = OffboardingExitInterview::where('offboarding_case_id', $caseId)->first();
                $cardTotal     = 1;
                $cardCompleted = ($interview && in_array($interview->status, ['completed', 'declined'], true)) ? 1 : 0;
            } elseif ($card->card_type === 'final_settlement') {
                $settlement    = OffboardingFinalSettlement::where('offboarding_case_id', $caseId)->first();
                $cardTotal     = 1;
                $cardCompleted = ($settlement && in_array($settlement->status, ['approved', 'paid'], true)) ? 1 : 0;
            } else {
                $tasks         = OffboardingTask::where('card_id', $card->id)
                    ->where('is_active', true)
                    ->where('is_applicable', true)
                    ->where('is_needed', true)
                    ->get();
                $cardTotal     = $tasks->count();
                $cardCompleted = $tasks->where('status', 'completed')->count();
            }

            $cardProgress = $cardTotal > 0 ? round(($cardCompleted / $cardTotal) * 100, 2) : 0;

            $card->update([
                'progress' => $cardProgress,
                'status'   => $this->resolveCardStatus($card->is_locked, $cardCompleted, $cardTotal),
            ]);

            $totalCompleted += $cardCompleted;
            $totalActive    += $cardTotal;
        }

        // Auto-unlock Final Settlement once Asset Return and Clearance both hit 100%.
        $assetCard     = $cards->firstWhere('card_type', 'asset_return');
        $clearanceCard = $cards->firstWhere('card_type', 'clearance');
        $settlementCard= $cards->firstWhere('card_type', 'final_settlement');

        if ($settlementCard && $settlementCard->is_locked
            && $assetCard && (float)$assetCard->progress >= 100
            && $clearanceCard && (float)$clearanceCard->progress >= 100) {
            $settlementCard->update([
                'is_locked'   => false,
                'status'      => 'pending',
                'unlocked_at' => now(),
                'unlocked_by' => Auth::id(),
            ]);
            $this->log($caseId, 'final_settlement_unlocked', 'Final Settlement unlocked — asset return and clearance both complete');
        }

        $overall = $totalActive > 0 ? round(($totalCompleted / $totalActive) * 100, 2) : 0;

        $case = OffboardingCase::find($caseId);
        if ($case) {
            $updateData = ['overall_progress' => $overall];
            if ($case->status === 'initiated') {
                $updateData['status'] = 'in_progress';
            }
            $case->update($updateData);
        }
    }

    /**
     * Lightweight progress data for endpoints that mutate a single item — lets the frontend
     * refresh the progress bar and card lock states without re-fetching the whole case.
     */
    public function progressSnapshot(int $caseId): array
    {
        return [
            'cards' => OffboardingCard::where('offboarding_case_id', $caseId)
                ->orderBy('sequence_order')
                ->get(['id', 'card_type', 'progress', 'is_locked', 'status', 'unlocked_at']),
            'overall_progress' => (float) (OffboardingCase::where('id', $caseId)->value('overall_progress') ?? 0),
        ];
    }

    private function openCase(int $caseId): OffboardingCase
    {
        $case = OffboardingCase::findOrFail($caseId);
        abort_if(
            in_array($case->status, ['completed', 'cancelled'], true),
            422,
            'This offboarding case is ' . $case->status . ' and can no longer be modified.'
        );

        return $case;
    }

    private function assertSettlementUnlocked(int $caseId): void
    {
        $isLocked = OffboardingCard::where('offboarding_case_id', $caseId)
            ->where('card_type', 'final_settlement')
            ->value('is_locked');

        abort_if((bool) $isLocked, 422, 'Final Settlement is locked until asset return and department clearance are both complete.');
    }

    private function assertAttachmentTarget(int $caseId, string $category, ?int $relatedId): void
    {
        match ($category) {
            'asset_return' => abort_unless(
                $relatedId && OffboardingAssetReturn::where('id', $relatedId)->where('offboarding_case_id', $caseId)->exists(),
                404,
                'Asset return item not found for this case.'
            ),
            'task' => abort_unless(
                $relatedId && OffboardingTask::where('id', $relatedId)->where('offboarding_case_id', $caseId)->exists(),
                404,
                'Task not found for this case.'
            ),
            'clearance', 'settlement', 'exit_interview' => null,
            default => abort(422, 'Invalid attachment category.'),
        };
    }

    private function resolveCardStatus(bool $isLocked, int $completed, int $total): string
    {
        if ($isLocked) return 'locked';
        if ($total === 0) return 'pending';
        if ($completed === $total) return 'completed';
        if ($completed > 0) return 'in_progress';
        return 'pending';
    }

    private function seedCards(OffboardingCase $case): void
    {
        foreach (self::CARD_CONFIG as $type => $config) {
            OffboardingCard::create([
                'offboarding_case_id' => $case->id,
                'card_type'           => $type,
                'title'               => $config['title'],
                'status'              => $config['locked'] ? 'locked' : 'pending',
                'is_locked'           => $config['locked'],
                'sequence_order'      => $config['seq'],
            ]);
        }

        $documentationCard = OffboardingCard::where('offboarding_case_id', $case->id)
            ->where('card_type', 'documentation')
            ->first();

        foreach (self::DOCUMENTATION_TASK_TEMPLATES as $t) {
            OffboardingTask::create([
                'offboarding_case_id' => $case->id,
                'card_id'             => $documentationCard->id,
                'task_code'           => $t['code'],
                'title'               => $t['title'],
                'is_required'         => true,
                'is_optional'         => false,
                'is_active'           => true,
                'is_applicable'       => true,
                'is_needed'           => true,
                'sequence_order'      => $t['seq'],
                'status'              => 'pending',
            ]);
        }
    }

    private function seedAssetReturns(OffboardingCase $case): void
    {
        foreach (self::ASSET_RETURN_DEFAULTS as $item) {
            OffboardingAssetReturn::create([
                'offboarding_case_id' => $case->id,
                'item_name'           => $item,
                'is_returned'         => false,
                'is_applicable'       => true,
                'is_needed'           => true,
            ]);
        }
    }

    private function seedClearances(OffboardingCase $case): void
    {
        foreach (self::CLEARANCE_DEFAULTS as $clearance) {
            OffboardingClearance::create([
                'offboarding_case_id' => $case->id,
                'clearance_key'       => $clearance['key'],
                'label'               => $clearance['label'],
                'department_tag'      => $clearance['department_tag'],
                'is_applicable'       => true,
                'is_needed'           => true,
                'status'              => 'pending',
            ]);
        }
    }

    private function log(int $caseId, string $action, string $description, array $metadata = []): void
    {
        OffboardingActivityLog::create([
            'offboarding_case_id' => $caseId,
            'actor_id'            => Auth::id(),
            'action'              => $action,
            'description'         => $description,
            'metadata'            => !empty($metadata) ? $metadata : null,
        ]);
    }

    private function notifyOffboarding(
        OffboardingCase $case,
        string $type,
        string $title,
        string $message,
        ?string $permission = null,
    ): void {
        $case->loadMissing(['employee.user', 'initiatedBy']);

        NotificationService::send(
            type: $type,
            title: $title,
            message: $message,
            module: 'hr',
            data: [
                'url' => "/hr/offboarding/{$case->id}",
                'record_type' => 'offboarding_case',
                'record_id' => $case->id,
                'employee_id' => $case->employee_id,
                'employee_name' => $case->employee->name,
                'actor_id' => Auth::id(),
            ],
            users: collect([$case->employee?->user, $case->initiatedBy])->filter()->all(),
            permission: $permission ?? [],
        );
    }
}
