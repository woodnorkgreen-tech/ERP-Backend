<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\OnboardingCase;
use App\Modules\HR\Models\OnboardingCard;
use App\Modules\HR\Models\OnboardingTask;
use App\Modules\HR\Models\OnboardingDocumentRequirement;
use App\Modules\HR\Models\OnboardingWelcomeKitItem;
use App\Modules\HR\Models\OnboardingHandover;
use App\Modules\HR\Models\OnboardingReview;
use App\Modules\HR\Models\OnboardingActivityLog;
use App\Modules\HR\Models\Candidate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class OnboardingService
{
    private const TASK_TEMPLATES = [
        'hr' => [
            ['code' => 'HR_EMPLOYEE_PROFILE',  'title' => 'Create Employee Profile',                      'is_optional' => false, 'seq' => 1],
            ['code' => 'HR_ORIENTATION',        'title' => 'Conduct Orientation Session',                  'is_optional' => false, 'seq' => 2],
            ['code' => 'HR_VISION_MISSION',     'title' => 'Company Vision, Mission & Values Briefing',    'is_optional' => false, 'seq' => 3],
            ['code' => 'HR_POLICIES',           'title' => 'Company Policies Briefing',                    'is_optional' => false, 'seq' => 4],
            ['code' => 'HR_CODE_OF_CONDUCT',    'title' => 'Code of Conduct Briefing',                     'is_optional' => false, 'seq' => 5],
            ['code' => 'HR_ROLE_EXPECTATIONS',  'title' => 'Role Expectations Discussion',                 'is_optional' => false, 'seq' => 6],
            ['code' => 'HR_MANAGER_MEETING',    'title' => 'HR & Line Manager Meeting with Employee',      'is_optional' => false, 'seq' => 7],
            ['code' => 'HR_PPE_ASSIGNMENT',     'title' => 'PPE Allocation',                               'is_optional' => true,  'seq' => 8],
        ],
        'day_1' => [
            ['code' => 'DAY1_ORIENTATION',    'title' => 'Day 1 Orientation',                         'is_optional' => false, 'seq' => 1],
            ['code' => 'DAY1_MANAGER_MEETING','title' => 'Line Manager Meeting',                       'is_optional' => false, 'seq' => 2],
            ['code' => 'DAY1_TEAM_INTRO',     'title' => 'Team Introduction',                         'is_optional' => false, 'seq' => 3],
            ['code' => 'DAY1_DESK_ALLOC',     'title' => 'Desk & Workspace Allocation',               'is_optional' => false, 'seq' => 4],
            ['code' => 'DAY1_EMAIL_SETUP',    'title' => 'Email & System Access Confirmation',        'is_optional' => false, 'seq' => 5],
            ['code' => 'DAY1_BIOMETRIC',      'title' => 'Biometric Enrollment',                      'is_optional' => true,  'seq' => 6],
            ['code' => 'DAY1_HANDOVER_PREP',  'title' => 'Handover Preparation',                      'is_optional' => false, 'seq' => 7],
        ],
        'it' => [
            ['code' => 'IT_USER_ACCOUNT',   'title' => 'Create ERP User Account',       'is_optional' => false, 'seq' => 1],
            ['code' => 'IT_LAPTOP',         'title' => 'Laptop & Hardware Assignment',   'is_optional' => false, 'seq' => 2],
            ['code' => 'IT_EMAIL_SETUP',    'title' => 'Company Email Setup',            'is_optional' => false, 'seq' => 3],
            ['code' => 'IT_SYSTEM_ACCESS',  'title' => 'System Access Configuration',   'is_optional' => false, 'seq' => 4],
            ['code' => 'IT_ID_BADGE',       'title' => 'Issue ID Badge',                'is_optional' => false, 'seq' => 5],
            ['code' => 'IT_BIOMETRIC',      'title' => 'Biometric Enrollment',          'is_optional' => true,  'seq' => 6],
        ],
        'sops' => [
            ['code' => 'SOPS_WALKTHROUGH', 'title' => 'Conduct SOPs Walkthrough',      'is_optional' => false, 'seq' => 1],
            ['code' => 'SOPS_PROCEDURES',  'title' => 'SOPs Documentation Review',     'is_optional' => false, 'seq' => 2],
            ['code' => 'SOPS_INDUCTION',   'title' => 'Operational Induction',         'is_optional' => false, 'seq' => 3],
        ],
    ];

    private const WELCOME_KIT_DEFAULTS = [
        'Laptop', 'Notebook', 'Company ID Card', 'Desk Assignment', 'Company T-Shirt', 'Water Bottle',
    ];

    private const DOCUMENT_REQUIREMENTS = [
        ['key' => 'signed_contract',          'label' => 'Signed Employment Contract',          'is_required' => true],
        ['key' => 'passport_photo',           'label' => 'Passport Photo',                      'is_required' => true],
        ['key' => 'national_id',              'label' => 'National ID Copy',                    'is_required' => true],
        ['key' => 'kra_copy',                 'label' => 'KRA Certificate Copy',               'is_required' => true],
        ['key' => 'certificate_good_conduct', 'label' => 'Certificate of Good Conduct',        'is_required' => true],
        ['key' => 'certificates',             'label' => 'Academic / Professional Certificates','is_required' => false],
    ];

    private const CARD_CONFIG = [
        'hr'          => ['title' => 'HR Onboarding',    'seq' => 1, 'locked' => false],
        'day_1'       => ['title' => 'Day 1 Activities', 'seq' => 2, 'locked' => false],
        'it'          => ['title' => 'IT Setup',         'seq' => 3, 'locked' => true],
        'sops'        => ['title' => 'SOPs Induction',   'seq' => 4, 'locked' => true],
        'welcome_kit' => ['title' => 'Welcome Kit',      'seq' => 5, 'locked' => false],
        'documents'   => ['title' => 'Employee Documents','seq' => 6, 'locked' => false],
    ];

    public function getHiredCandidates(): Collection
    {
        $activeCaseIds = OnboardingCase::whereNotIn('status', ['cancelled', 'completed'])
            ->pluck('candidate_id');

        return Candidate::where('status', 'Hired')
            ->whereNotIn('id', $activeCaseIds)
            ->with('jobPosting')
            ->latest()
            ->get();
    }

    public function startOnboarding(array $data): OnboardingCase
    {
        return DB::transaction(function () use ($data) {
            $case = OnboardingCase::create([
                'candidate_id'    => $data['candidate_id'],
                'job_posting_id'  => $data['job_posting_id'] ?? null,
                'started_from'    => 'recruitment',
                'status'          => 'pre_onboarding', // starts in Pre-Onboarding column
                'start_date'      => $data['start_date'] ?? null,
                'hr_owner_id'     => $data['hr_owner_id'] ?? Auth::id(),
                'department_lead_id' => $data['department_lead_id'] ?? null,
                'employment_type' => $data['employment_type'] ?? 'full-time',
                'department_id'   => $data['department_id'] ?? null,
                'position'        => $data['position'] ?? null,
                'notes'           => $data['notes'] ?? null,
            ]);

            $this->seedCards($case);
            $this->seedWelcomeKit($case);
            $this->seedDocumentRequirements($case);

            $this->log($case->id, 'onboarding_started', 'Onboarding initiated from recruitment');

            return $case->load(['candidate.jobPosting', 'cards.tasks', 'documentRequirements', 'welcomeKitItems']);
        });
    }

    public function linkEmployee(int $caseId, int $employeeId): OnboardingCase
    {
        $case = OnboardingCase::findOrFail($caseId);
        $case->update(['employee_id' => $employeeId]);

        // Auto-complete the employee profile task
        OnboardingTask::where('onboarding_case_id', $caseId)
            ->where('task_code', 'HR_EMPLOYEE_PROFILE')
            ->whereNot('status', 'completed')
            ->update([
                'status'       => 'completed',
                'completed_at' => now(),
                'completed_by' => Auth::id(),
            ]);

        $this->recalculateProgress($caseId);
        $this->log($caseId, 'employee_linked', "Employee profile created and linked (Employee ID: {$employeeId})");

        return $case->fresh();
    }

    public function completeTaskById(int $taskId, ?string $notes = null): OnboardingTask
    {
        $task = OnboardingTask::findOrFail($taskId);

        $task->update([
            'status'       => 'completed',
            'completed_at' => now(),
            'completed_by' => Auth::id(),
            'notes'        => $notes ?? $task->notes,
        ]);

        $this->recalculateProgress($task->onboarding_case_id);
        $this->log($task->onboarding_case_id, 'task_completed', "Task completed: {$task->title}");

        return $task->fresh();
    }

    public function reopenTaskById(int $taskId): OnboardingTask
    {
        $task = OnboardingTask::findOrFail($taskId);

        $task->update([
            'status'       => 'pending',
            'completed_at' => null,
            'completed_by' => null,
        ]);

        $this->recalculateProgress($task->onboarding_case_id);
        $this->log($task->onboarding_case_id, 'task_reopened', "Task reopened: {$task->title}");

        return $task->fresh();
    }

    public function toggleOptionalTask(int $taskId, bool $isActive): OnboardingTask
    {
        $task = OnboardingTask::findOrFail($taskId);
        abort_if(!$task->is_optional, 422, 'Only optional tasks can be toggled.');

        $task->update(['is_active' => $isActive]);
        $this->recalculateProgress($task->onboarding_case_id);

        return $task;
    }

    public function createTask(int $cardId, array $fields): OnboardingTask
    {
        $card = OnboardingCard::findOrFail($cardId);
        $isOptional = array_key_exists('is_optional', $fields) && (bool)$fields['is_optional'];

        $slug = preg_replace('/[^A-Z0-9]+/', '_', strtoupper($fields['title']));
        $slug = trim($slug, '_');
        $code = $slug ?: 'CUSTOM_TASK';
        $baseCode = $code;
        $suffix = 1;
        while (OnboardingTask::where('onboarding_case_id', $card->onboarding_case_id)->where('task_code', $code)->exists()) {
            $code = $baseCode . '_' . $suffix++;
        }

        $highestOrder = OnboardingTask::where('card_id', $cardId)->max('sequence_order') ?? 0;

        $task = OnboardingTask::create([
            'onboarding_case_id' => $card->onboarding_case_id,
            'card_id'            => $cardId,
            'task_code'          => $code,
            'title'              => $fields['title'],
            'description'        => $fields['description'] ?? null,
            'assignee_role'      => $fields['assignee_role'] ?? null,
            'is_required'        => !$isOptional,
            'is_optional'        => $isOptional,
            'is_active'          => true,
            'is_applicable'      => true,
            'is_needed'          => true,
            'sequence_order'     => $highestOrder + 1,
            'status'             => 'pending',
        ]);

        $this->recalculateProgress($card->onboarding_case_id);
        $this->log($card->onboarding_case_id, 'task_created', "Custom task created: {$task->title}");

        return $task;
    }

    public function createDocumentRequirement(int $caseId, array $fields): OnboardingDocumentRequirement
    {
        $case = OnboardingCase::findOrFail($caseId);
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($fields['label']));
        $slug = trim($slug, '_');
        $key = $slug ?: 'custom_document';
        $baseKey = $key;
        $suffix = 1;
        while (OnboardingDocumentRequirement::where('onboarding_case_id', $caseId)->where('document_key', $key)->exists()) {
            $key = $baseKey . '_' . $suffix++;
        }

        $req = OnboardingDocumentRequirement::create([
            'onboarding_case_id' => $caseId,
            'document_key'       => $key,
            'label'              => $fields['label'],
            'is_required'        => array_key_exists('is_required', $fields) ? (bool)$fields['is_required'] : true,
            'is_applicable'      => true,
            'is_needed'          => true,
            'status'             => 'pending',
        ]);

        $this->log($caseId, 'document_created', "Custom document requirement created: {$req->label}");
        $this->recalculateProgress($caseId);

        return $req;
    }

    public function createWelcomeKitItem(int $caseId, array $fields): OnboardingWelcomeKitItem
    {
        $case = OnboardingCase::findOrFail($caseId);

        $item = OnboardingWelcomeKitItem::create([
            'onboarding_case_id' => $caseId,
            'item_name'          => $fields['item_name'],
            'is_ready'           => false,
            'is_applicable'      => true,
            'is_needed'          => true,
        ]);

        $this->log($caseId, 'welcome_kit_item_created', "Custom welcome kit item created: {$item->item_name}");
        $this->recalculateProgress($caseId);

        return $item;
    }

    public function approveHRGate(int $caseId, ?string $notes = null): OnboardingCase
    {
        $case = OnboardingCase::findOrFail($caseId);

        DB::transaction(function () use ($case, $notes) {
            $case->update([
                'hr_approved_at' => now(),
                'hr_approved_by' => Auth::id(),
                'it_unlocked_at' => now(),
                'sops_unlocked_at' => now(),
                'status' => 'department_onboarding',
            ]);

            OnboardingCard::where('onboarding_case_id', $case->id)
                ->whereIn('card_type', ['it', 'sops'])
                ->update([
                    'is_locked'   => false,
                    'status'      => 'pending',
                    'unlocked_at' => now(),
                    'unlocked_by' => Auth::id(),
                ]);

            $this->recalculateProgress($case->id);
            $this->log($case->id, 'hr_gate_approved', 'HR approval gate passed — IT and SOPs onboarding unlocked');
        });

        return $case->fresh(['cards.tasks', 'documentRequirements', 'welcomeKitItems']);
    }

    public function recordHandover(int $caseId, array $data): OnboardingHandover
    {
        $case = OnboardingCase::findOrFail($caseId);

        $handover = OnboardingHandover::updateOrCreate(
            ['onboarding_case_id' => $caseId],
            [
                'handed_over_by'              => Auth::id(),
                'handed_over_to_employee_id'  => $data['department_lead_id'] ?? $case->department_lead_id,
                'handover_notes'              => $data['handover_notes'] ?? null,
                'handover_date'               => $data['handover_date'] ?? now()->toDateString(),
                'status'                      => 'completed',
                'completed_at'                => now(),
            ]
        );

        $case->update(['status' => 'handover']);
        $this->log($caseId, 'handover_completed', 'HR formally handed over onboarding to line manager');

        return $handover->load(['handedOverByUser', 'departmentLead']);
    }

    public function submitReview(int $caseId, array $data): OnboardingReview
    {
        $review = OnboardingReview::updateOrCreate(
            ['onboarding_case_id' => $caseId, 'review_type' => $data['review_type']],
            [
                'scheduled_date'    => $data['scheduled_date'] ?? null,
                'conducted_date'    => $data['conducted_date'] ?? now()->toDateString(),
                'conducted_by'      => Auth::id(),
                'performance_rating'=> $data['performance_rating'] ?? null,
                'feedback'          => $data['feedback'] ?? null,
                'improvement_notes' => $data['improvement_notes'] ?? null,
                'employee_feedback' => $data['employee_feedback'] ?? null,
                'status'            => 'completed',
            ]
        );

        $case = OnboardingCase::find($caseId);

        if (in_array($case->status, ['handover', 'post_onboarding_review'])) {
            $case->update(['status' => 'post_onboarding_review']);
        }

        $completedReviews = OnboardingReview::where('onboarding_case_id', $caseId)
            ->where('status', 'completed')
            ->count();

        if ($completedReviews >= 2) {
            $case->update(['status' => 'completed', 'completed_at' => now()]);
            $this->log($caseId, 'onboarding_completed', 'Onboarding fully completed after post-onboarding reviews');
        } else {
            $this->log($caseId, 'review_submitted', "Post-onboarding review submitted: {$data['review_type']}");
        }

        return $review;
    }

    public function updateDocumentStatus(int $requirementId, string $status, ?string $rejectionReason = null): OnboardingDocumentRequirement
    {
        $req = OnboardingDocumentRequirement::findOrFail($requirementId);

        $updateData = ['status' => $status];

        if ($status === 'verified') {
            $updateData['verified_at'] = now();
            $updateData['verified_by'] = Auth::id();
        }
        if ($status === 'rejected') {
            $updateData['rejection_reason'] = $rejectionReason;
        }
        if ($status === 'submitted') {
            $updateData['submitted_at'] = now();
        }

        $req->update($updateData);
        $this->log($req->onboarding_case_id, 'document_updated', "Document '{$req->label}' → {$status}");
        $this->recalculateProgress($req->onboarding_case_id);

        return $req;
    }

    public function updateWelcomeKitItem(int $itemId, array $fields): OnboardingWelcomeKitItem
    {
        $item = OnboardingWelcomeKitItem::findOrFail($itemId);
        $updateData = [];

        if (array_key_exists('is_applicable', $fields)) {
            $updateData['is_applicable'] = (bool)$fields['is_applicable'];
            if (!$updateData['is_applicable']) {
                $updateData['is_ready'] = false;
                $updateData['marked_ready_by'] = null;
                $updateData['marked_ready_at'] = null;
            }
        }

        if (array_key_exists('is_needed', $fields)) {
            $updateData['is_needed'] = (bool)$fields['is_needed'];
            if (!$updateData['is_needed']) {
                $updateData['is_ready'] = false;
                $updateData['marked_ready_by'] = null;
                $updateData['marked_ready_at'] = null;
            }
        }

        if (!empty($updateData)) {
            $item->update($updateData);
            $this->recalculateProgress($item->onboarding_case_id);
        }

        return $item;
    }

    public function toggleWelcomeKitItem(int $itemId): OnboardingWelcomeKitItem
    {
        $item = OnboardingWelcomeKitItem::findOrFail($itemId);
        abort_if(!$item->is_applicable || !$item->is_needed, 422, 'Item is not applicable.');

        $item->update([
            'is_ready'        => !$item->is_ready,
            'marked_ready_by' => Auth::id(),
            'marked_ready_at' => now(),
        ]);

        $this->recalculateProgress($item->onboarding_case_id);
        return $item;
    }

    public function cancelOnboarding(int $caseId, ?string $reason = null): OnboardingCase
    {
        $case = OnboardingCase::findOrFail($caseId);

        $case->update([
            'status'       => 'cancelled',
            'cancelled_at' => now(),
            'notes'        => $reason ?? $case->notes,
        ]);

        $this->log($caseId, 'onboarding_cancelled', 'Onboarding cancelled' . ($reason ? ": {$reason}" : ''));

        return $case;
    }

    public function recalculateProgress(int $caseId): void
    {
        $cards = OnboardingCard::where('onboarding_case_id', $caseId)->get();

        $totalCompleted = 0;
        $totalActive    = 0;

        foreach ($cards as $card) {
            if ($card->is_locked) {
                $card->update(['progress' => 0]);
                continue;
            }

            if ($card->card_type === 'welcome_kit') {
                $items = OnboardingWelcomeKitItem::where('onboarding_case_id', $caseId)
                    ->where('is_applicable', true)
                    ->where('is_needed', true)
                    ->get();

                $cardTotal     = $items->count();
                $cardCompleted = $items->where('is_ready', true)->count();
            } elseif ($card->card_type === 'documents') {
                $requirements = OnboardingDocumentRequirement::where('onboarding_case_id', $caseId)
                    ->where('is_applicable', true)
                    ->where('is_needed', true)
                    ->get();

                $cardTotal     = $requirements->count();
                $cardCompleted = $requirements->filter(function ($req) {
                    return in_array($req->status, ['submitted', 'verified']);
                })->count();
            } else {
                $tasks         = OnboardingTask::where('card_id', $card->id)
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

        $overall = $totalActive > 0 ? round(($totalCompleted / $totalActive) * 100, 2) : 0;

        $case = OnboardingCase::find($caseId);
        if ($case) {
            $updateData = ['overall_progress' => $overall];
            if ($overall >= 100 && !in_array($case->status, ['handover', 'hr_handover_completed', 'post_onboarding_review', 'completed', 'cancelled'], true)) {
                $updateData['status'] = 'handover';
                $this->log($caseId, 'auto_handover', 'Onboarding case moved to handover automatically at 100% progress');
            }
            $case->update($updateData);
        }
    }

    private function resolveCardStatus(bool $isLocked, int $completed, int $total): string
    {
        if ($isLocked) return 'locked';
        if ($total === 0) return 'pending';
        if ($completed === $total) return 'completed';
        if ($completed > 0) return 'in_progress';
        return 'pending';
    }

    private function seedCards(OnboardingCase $case): void
    {
        foreach (self::CARD_CONFIG as $type => $config) {
            $card = OnboardingCard::create([
                'onboarding_case_id' => $case->id,
                'card_type'          => $type,
                'title'              => $config['title'],
                'status'             => $config['locked'] ? 'locked' : 'pending',
                'is_locked'          => $config['locked'],
                'sequence_order'     => $config['seq'],
            ]);

            if (isset(self::TASK_TEMPLATES[$type])) {
                foreach (self::TASK_TEMPLATES[$type] as $t) {
                    OnboardingTask::create([
                        'onboarding_case_id' => $case->id,
                        'card_id'            => $card->id,
                        'task_code'          => $t['code'],
                        'title'              => $t['title'],
                        'is_optional'        => $t['is_optional'],
                        'is_required'        => !$t['is_optional'],
                        'is_active'          => !$t['is_optional'], // optional tasks start inactive
                        'is_applicable'      => true,
                        'is_needed'          => true,
                        'sequence_order'     => $t['seq'],
                        'status'             => 'pending',
                    ]);
                }
            }
        }
    }

    private function seedWelcomeKit(OnboardingCase $case): void
    {
        foreach (self::WELCOME_KIT_DEFAULTS as $item) {
            OnboardingWelcomeKitItem::create([
                'onboarding_case_id' => $case->id,
                'item_name'          => $item,
                'is_ready'           => false,
                'is_applicable'      => true,
                'is_needed'          => true,
            ]);
        }
    }

    private function seedDocumentRequirements(OnboardingCase $case): void
    {
        foreach (self::DOCUMENT_REQUIREMENTS as $doc) {
            OnboardingDocumentRequirement::create([
                'onboarding_case_id' => $case->id,
                'document_key'       => $doc['key'],
                'label'              => $doc['label'],
                'is_required'        => $doc['is_required'],
                'is_applicable'      => true,
                'is_needed'          => true,
                'status'             => 'pending',
            ]);
        }
    }

    public function updateTaskFlags(int $taskId, array $fields): OnboardingTask
    {
        $task = OnboardingTask::findOrFail($taskId);
        $updateData = [];

        if (array_key_exists('is_applicable', $fields)) {
            $updateData['is_applicable'] = (bool)$fields['is_applicable'];
        }

        if (array_key_exists('is_needed', $fields)) {
            $updateData['is_needed'] = (bool)$fields['is_needed'];
        }

        if (!empty($updateData)) {
            $task->update($updateData);
            $this->recalculateProgress($task->onboarding_case_id);
        }

        return $task;
    }

    public function updateDocumentRequirementFlags(int $requirementId, array $fields): OnboardingDocumentRequirement
    {
        $req = OnboardingDocumentRequirement::findOrFail($requirementId);
        $updateData = [];

        if (array_key_exists('is_applicable', $fields)) {
            $updateData['is_applicable'] = (bool)$fields['is_applicable'];
        }

        if (array_key_exists('is_needed', $fields)) {
            $updateData['is_needed'] = (bool)$fields['is_needed'];
        }

        if (!empty($updateData)) {
            $req->update($updateData);
        }

        return $req;
    }

    private function log(int $caseId, string $action, string $description, array $metadata = []): void
    {
        OnboardingActivityLog::create([
            'onboarding_case_id' => $caseId,
            'actor_id'           => Auth::id(),
            'action'             => $action,
            'description'        => $description,
            'metadata'           => !empty($metadata) ? $metadata : null,
        ]);
    }
}
