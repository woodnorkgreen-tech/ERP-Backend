<?php

namespace App\Modules\ArchivalTask\Services;

use App\Modules\ArchivalTask\Models\ArchivalReport;
use App\Modules\ArchivalTask\Models\ArchivalSetupItem;
use App\Modules\ArchivalTask\Models\ArchivalItemPlacement;
use App\Modules\Projects\Models\EnquiryTask;
use App\Models\SiteSurvey;
use App\Models\HandoverSurvey;
use App\Models\DesignAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ArchivalReportService
{
    public function getReportByTask(int $taskId): ?array
    {
        $report = ArchivalReport::with(['setupItems', 'itemPlacements', 'creator', 'correctionRequester'])
            ->where('enquiry_task_id', $taskId)
            ->first();

        if (!$report) {
            return null;
        }

        $reportData = $report->toArray();
        $reportData['attachment_urls'] = $report->attachment_urls;
        $reportData['system_documents'] = $this->getSystemDocuments($taskId);
        $reportData['financial_summary'] = $this->getFinancialSummary($taskId);
        $reportData['closure_context'] = $this->getClosureContext($taskId);
        $reportData['handover_summary'] = $this->getHandoverSummary($taskId);
        $user = auth()->user();
        $isReviewer = $user?->hasRole(['Super Admin', 'Admin', 'Project Manager']) ?? false;
        $reportData['capabilities'] = [
            'can_edit' => $report->status === 'draft',
            'can_approve' => $report->status === 'submitted' && $isReviewer,
            'can_return' => $report->status === 'submitted' && $isReviewer,
        ];

        return $reportData;
    }

    /**
     * Build the closure controls from the project's real task set. This uses
     * the instantiated workflow rather than assuming every preset has survey,
     * production, setup and set-down stages.
     */
    public function getClosureContext(int $taskId): array
    {
        $task = EnquiryTask::with('enquiry')->findOrFail($taskId);
        $enquiry = $task->enquiry;
        $tasks = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
            ->orderBy('task_order')
            ->orderBy('id')
            ->get();
        $operationalTasks = $tasks->where('type', '!=', 'report')->values();
        $templates = collect(config('enquiry_workflow.task_templates', []))->keyBy('type');
        $presetKey = $enquiry?->workflow_preset_type;
        $preset = $presetKey ? config("enquiry_workflow.task_presets.{$presetKey}", []) : [];
        $completed = $operationalTasks->where('status', 'completed')->count();
        $total = $operationalTasks->count();
        $documents = collect($this->getSystemDocuments($taskId));

        $checks = collect($this->closureCheckDefinitions())
            ->filter(function (array $definition) use ($operationalTasks) {
                return $operationalTasks->whereIn('type', $definition['task_types'])->isNotEmpty();
            })
            ->map(function (array $definition) use ($operationalTasks, $documents) {
                $matchingTasks = $operationalTasks->whereIn('type', $definition['task_types']);
                $presentTypes = $matchingTasks->pluck('type')->unique()->values();
                $label = is_callable($definition['label'])
                    ? $definition['label']($presentTypes->all())
                    : $definition['label'];

                return [
                    'key' => $definition['key'],
                    'label' => $label,
                    'task_types' => $presentTypes->all(),
                    'task_ids' => $matchingTasks->pluck('id')->values()->all(),
                    'auto_verified' => $matchingTasks->every(fn (EnquiryTask $item) => $item->status === 'completed'),
                    'evidence_count' => $documents->whereIn('category', $definition['evidence_categories'])->count(),
                ];
            })
            ->values();

        $firstActivity = $operationalTasks->min(fn (EnquiryTask $item) => $item->started_at?->timestamp ?? $item->created_at?->timestamp);
        $lastActivity = $operationalTasks->max(fn (EnquiryTask $item) => $item->completed_at?->timestamp ?? $item->updated_at?->timestamp);

        return [
            'preset_key' => $presetKey,
            'preset_label' => $preset['label'] ?? 'Custom project workflow',
            'preset_description' => $preset['description'] ?? 'Closure follows the tasks configured for this project.',
            'task_summary' => [
                'total' => $total,
                'completed' => $completed,
                'open' => max($total - $completed, 0),
                'completion_percentage' => $total > 0 ? (int) round(($completed / $total) * 100) : 100,
            ],
            'timeline' => [
                'started_at' => $firstActivity ? now()->setTimestamp($firstActivity)->toISOString() : null,
                'last_activity_at' => $lastActivity ? now()->setTimestamp($lastActivity)->toISOString() : null,
                'duration_days' => $firstActivity && $lastActivity
                    ? max(1, (int) ceil(($lastActivity - $firstActivity) / 86400))
                    : null,
            ],
            'tasks' => $operationalTasks->map(fn (EnquiryTask $item) => [
                'id' => $item->id,
                'type' => $item->type,
                'title' => $item->title,
                'phase' => data_get($templates->get($item->type), 'phase', 'Project workflow'),
                'status' => $item->status,
                'completed_at' => $item->completed_at?->toISOString(),
            ])->values()->all(),
            'required_checks' => $checks->all(),
        ];
    }

    public function getRequiredChecklistFields(int $taskId): array
    {
        return collect($this->getClosureContext($taskId)['required_checks'] ?? [])->pluck('key')->all();
    }

    public function getMissingRequiredChecks(int $taskId, ArchivalReport $report): array
    {
        return collect($this->getClosureContext($taskId)['required_checks'] ?? [])
            ->filter(fn (array $check) => !$check['auto_verified'] || !$report->{$check['key']})
            ->values()
            ->all();
    }

    private function closureCheckDefinitions(): array
    {
        return [
            [
                'key' => 'checklist_site_survey_form',
                'label' => 'Site survey completed and filed',
                'task_types' => ['site-survey'],
                'evidence_categories' => ['Field Work'],
            ],
            [
                'key' => 'checklist_ppt',
                'label' => 'Approved design recorded',
                'task_types' => ['design'],
                'evidence_categories' => ['Design'],
            ],
            [
                'key' => 'checklist_cutlist',
                'label' => fn (array $types) => in_array('procurement', $types, true)
                    ? 'Commercial approval and procurement closed'
                    : 'Commercial approval recorded',
                'task_types' => ['quote', 'quote_approval', 'procurement'],
                'evidence_categories' => ['Quote', 'Procurement'],
            ],
            [
                'key' => 'checklist_project_budget_file',
                'label' => 'Approved budget filed',
                'task_types' => ['budget'],
                'evidence_categories' => ['Financials'],
            ],
            [
                'key' => 'checklist_material_list',
                'label' => 'Final material list filed',
                'task_types' => ['materials'],
                'evidence_categories' => ['Materials'],
            ],
            [
                'key' => 'checklist_qc_checklist',
                'label' => 'Production and quality controls closed',
                'task_types' => ['production'],
                'evidence_categories' => ['Production'],
            ],
            [
                'key' => 'checklist_setup_setdown',
                'label' => fn (array $types) => in_array('setdown', $types, true)
                    ? 'Dispatch, setup and returns closed'
                    : (in_array('setup', $types, true) ? 'Dispatch and setup closed' : 'Dispatch closed'),
                'task_types' => ['logistics', 'setup', 'setdown'],
                'evidence_categories' => ['Logistics'],
            ],
            [
                'key' => 'checklist_client_feedback',
                'label' => 'Client handover and feedback filed',
                'task_types' => ['handover'],
                'evidence_categories' => ['Handover'],
            ],
        ];
    }

    /**
     * Read-only reconciliation from the existing quote and payment records.
     * An unpaid balance stays owned by Finance and is disclosed at closure; it
     * does not duplicate or replace the receivables workflow.
     */
    public function getFinancialSummary(int $taskId): array
    {
        $task = EnquiryTask::findOrFail($taskId);
        $enquiry = $task->enquiry;

        if (!$enquiry) {
            return [];
        }

        $quoteTaskIds = EnquiryTask::where('project_enquiry_id', $enquiry->id)
            ->whereIn('type', ['quote', 'quote_approval'])
            ->pluck('id');
        $quote = \App\Models\TaskQuoteData::whereIn('enquiry_task_id', $quoteTaskIds)
            ->orderByDesc('approval_date')
            ->orderByDesc('id')
            ->first();

        $quotedAmount = (float) ($enquiry->client_approved_quote
            ?? $quote?->quote_amount
            ?? $quote?->excel_quote_amount
            ?? data_get($quote?->totals, 'grand_total', 0));
        $paidAmount = (float) \App\Models\EnquiryPayment::where('project_enquiry_id', $enquiry->id)->sum('amount');
        $balance = max($quotedAmount - $paidAmount, 0);

        return [
            'currency' => 'KES',
            'approved_quote' => round($quotedAmount, 2),
            'payments_received' => round($paidAmount, 2),
            'outstanding_balance' => round($balance, 2),
            'payment_status' => $quotedAmount <= 0 ? 'not_available' : ($balance <= 0 ? 'settled' : ($paidAmount > 0 ? 'part_paid' : 'unpaid')),
            'finance_released' => (bool) $enquiry->finance_released,
            'note' => $balance > 0 ? 'Outstanding collection remains active in Finance after operational closure.' : null,
        ];
    }

    /**
     * Normalize the exact questionnaire snapshot used at handover. Keeping the
     * snapshot labels makes old reports stable even when the live questionnaire
     * is changed later.
     */
    public function getHandoverSummary(int $taskId): ?array
    {
        $task = EnquiryTask::findOrFail($taskId);
        $handover = HandoverSurvey::with(['reviewer', 'ncrReport'])
            ->whereHas('task', fn ($query) => $query->where('project_enquiry_id', $task->project_enquiry_id))
            ->where('submitted', true)
            ->whereNotNull('responses')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->first();

        if (!$handover) {
            return null;
        }

        $responses = $handover->responses ?? [];
        $config = $handover->question_config_snapshot ?: config('survey_questions', []);
        $coveredIds = [];
        $sections = collect($config['sections'] ?? [])->map(function (array $section) use ($responses, &$coveredIds) {
            $answers = collect($section['questions'] ?? [])->map(function (array $question) use ($responses, &$coveredIds) {
                $id = $question['id'] ?? null;
                if (!$id || !array_key_exists($id, $responses)) {
                    return null;
                }

                $coveredIds[] = $id;
                $answer = $this->formatHandoverAnswer($responses[$id], $question['type'] ?? 'text');
                if ($answer['value'] === null && !$answer['remarks']) {
                    return null;
                }

                return [
                    'id' => $id,
                    'label' => $question['label'] ?? Str::headline($id),
                    'type' => $question['type'] ?? 'text',
                    ...$answer,
                ];
            })->filter()->values()->all();

            return empty($answers) ? null : [
                'id' => $section['id'] ?? null,
                'title' => $section['title'] ?? 'Client feedback',
                'answers' => $answers,
            ];
        })->filter()->values();

        $unmapped = collect($responses)->except($coveredIds)->map(function ($value, string $id) {
            $answer = $this->formatHandoverAnswer($value, 'text');
            return ($answer['value'] === null && !$answer['remarks']) ? null : [
                'id' => $id,
                'label' => Str::headline($id),
                'type' => 'text',
                ...$answer,
            ];
        })->filter()->values()->all();

        if (!empty($unmapped)) {
            $sections->push(['id' => 'additional', 'title' => 'Additional responses', 'answers' => $unmapped]);
        }

        $deliveredOnTime = array_key_exists('delivered_on_time', $responses)
            ? in_array($responses['delivered_on_time'], [true, 1, '1', 'true', 'yes'], true)
            : null;
        $ratings = $sections->flatMap(fn (array $section) => $section['answers'])
            ->where('type', 'rating')
            ->pluck('value')
            ->map(fn ($value) => is_numeric($value) ? (float) $value : (float) Str::before((string) $value, ' '))
            ->filter(fn (float $value) => $value >= 1 && $value <= 5);

        return [
            'submitted_at' => $handover->submitted_at?->toISOString(),
            'respondent' => $handover->respondent_info['name'] ?? data_get($responses, 'respondent_info') ?? 'Client representative',
            'respondent_info' => $handover->respondent_info ?? [],
            'feedback_source' => Str::headline($handover->feedback_source ?: 'survey_link'),
            'average_rating' => $ratings->isNotEmpty() ? round($ratings->avg(), 1) : null,
            'delivered_on_time' => $deliveredOnTime,
            'review_status' => $handover->review_status ?? 'pending',
            'reviewed_by' => $handover->reviewer?->name,
            'reviewed_at' => $handover->reviewed_at?->toISOString(),
            'review_notes' => $handover->review_notes,
            'evidence_notes' => $handover->evidence_notes,
            'sections' => $sections->all(),
            'ncr' => $handover->ncrReport ? [
                'reference' => $handover->ncrReport->title,
                'status' => $handover->ncrReport->status,
                'description' => $handover->ncrReport->description,
                'corrective_action' => $handover->ncrReport->corrective_action,
            ] : null,
        ];
    }

    private function formatHandoverAnswer(mixed $rawValue, string $type): array
    {
        $remarks = is_array($rawValue) ? ($rawValue['remarks'] ?? null) : null;
        $value = is_array($rawValue) ? ($rawValue['rating'] ?? $rawValue['value'] ?? null) : $rawValue;

        if ($type === 'rating' && is_numeric($value)) {
            $value = number_format((float) $value, 1) . ' / 5';
        } elseif ($type === 'yes_no') {
            $value = in_array($value, [true, 1, '1', 'true', 'yes'], true) ? 'Yes' : 'No';
        } elseif (is_array($value)) {
            $value = collect($value)->filter(fn ($item) => is_scalar($item))->implode(', ');
        } elseif (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        } elseif ($value !== null) {
            $value = trim((string) $value);
        }

        return [
            'value' => $value === '' ? null : $value,
            'remarks' => $remarks ? trim((string) $remarks) : null,
        ];
    }

    /**
     * Get system generated documents for the project
     */
    public function getSystemDocuments(int $taskId): array
    {
        try {
            $task = EnquiryTask::findOrFail($taskId);
            $enquiryId = $task->project_enquiry_id;

            if (!$enquiryId) {
                return [];
            }

            $documents = [];
            
            // Optimization: Fetch all valid tasks for this enquiry key types eager loaded
            $allTasks = EnquiryTask::where('project_enquiry_id', $enquiryId)
                ->whereIn('type', ['materials', 'budget', 'procurement', 'logistics', 'design'])
                ->get()
                ->keyBy('type');

            // 1. Fetch Material List PDF
            try {
                if (isset($allTasks['materials'])) {
                    $materialsTask = $allTasks['materials'];
                    if (\App\Models\TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)->exists()) {
                        $documents[] = [
                            'name' => 'Material List', 'type' => 'PDF', 'category' => 'Materials',
                            'url' => "/api/projects/tasks/{$materialsTask->id}/materials/pdf",
                            'task_id' => $materialsTask->id, 'task_status' => $materialsTask->status,
                        ];
                    }
                }
            } catch (\Exception $e) {}

            // 2. Fetch Budget PDF
            try {
               if (isset($allTasks['budget'])) {
                    $budgetTask = $allTasks['budget'];
                    if (\App\Models\TaskBudgetData::where('enquiry_task_id', $budgetTask->id)->exists()) {
                        $documents[] = [
                            'name' => 'Project Budget', 'type' => 'PDF', 'category' => 'Financials',
                            'url' => "/api/projects/tasks/{$budgetTask->id}/budget/pdf",
                            'task_id' => $budgetTask->id, 'task_status' => $budgetTask->status,
                        ];
                    }
                }
            } catch (\Exception $e) {}

            // 3. Fetch Logistics Report PDF
            try {
                if (isset($allTasks['logistics'])) {
                    $logisticsTask = $allTasks['logistics'];
                    if (\App\Modules\logisticsTask\Models\LogisticsTask::where('task_id', $logisticsTask->id)->exists()) {
                        $documents[] = [
                            'name' => 'Logistics Loading and Returns Report', 'type' => 'PDF', 'category' => 'Logistics',
                            'url' => "/api/projects/tasks/{$logisticsTask->id}/logistics/pdf",
                            'task_id' => $logisticsTask->id, 'task_status' => $logisticsTask->status,
                        ];
                    }
                }
            } catch (\Exception $e) {}

            // 4. Procurement close-out
            try {
                if (isset($allTasks['procurement'])) {
                    $procurementTask = $allTasks['procurement'];
                    if (\App\Models\TaskProcurementData::where('enquiry_task_id', $procurementTask->id)->exists()) {
                        $documents[] = [
                            'name' => 'Procurement Close-out', 'type' => 'PDF', 'category' => 'Procurement',
                            'url' => "/api/projects/tasks/{$procurementTask->id}/procurement/pdf",
                            'task_id' => $procurementTask->id, 'task_status' => $procurementTask->status,
                        ];
                    }
                }
            } catch (\Exception $e) {}

            // 5. Fetch Design Assets
            try {
                if (isset($allTasks['design'])) {
                    $designTask = $allTasks['design'];
                    $assets = DesignAsset::where('enquiry_task_id', $designTask->id)->get();
                    foreach ($assets as $asset) {
                        $documents[] = [
                            'name' => "Design: {$asset->name}",
                            'type' => $asset->isLink() ? 'LINK' : strtoupper(pathinfo($asset->file_path, PATHINFO_EXTENSION)),
                            'category' => 'Design',
                            'url' => $asset->isLink() ? $asset->external_url : storage_url($asset->file_path),
                            'asset_id' => $asset->id,
                            'task_status' => $designTask->status,
                        ];
                    }
                }
            } catch (\Exception $e) {}

            // 6. Fetch Site Survey PDF
            try {
                $survey = SiteSurvey::where('project_enquiry_id', $enquiryId)->first();
                if ($survey) {
                    $documents[] = [
                        'name' => 'Site Survey Report',
                        'type' => 'PDF',
                        'category' => 'Field Work',
                        'url' => "/api/projects/site-surveys/{$survey->id}/pdf",
                        'survey_id' => $survey->id,
                        'task_status' => $survey->status,
                    ];
                }
            } catch (\Exception $e) {}

            // 7. Fetch Handover Survey
            try {
                $handover = HandoverSurvey::whereHas('task', function($q) use ($enquiryId) {
                    $q->where('project_enquiry_id', $enquiryId);
                })->first();
                if ($handover) {
                     $documents[] = [
                        'name' => 'Handover Certificate',
                        'type' => 'PDF',
                        'category' => 'Handover',
                        'url' => "/api/projects/tasks/{$handover->task_id}/handover/survey",
                        'handover_id' => $handover->id,
                        'task_status' => $handover->submitted ? 'completed' : 'pending',
                    ];
                }
            } catch (\Exception $e) {}

            return $documents;
        } catch (\Exception $e) {
            Log::error('Failed to get system documents for archival report', [
                'task_id' => $taskId ?? null,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get archival report by ID
     */
    public function getReportById(int $reportId): ?ArchivalReport
    {
        return ArchivalReport::with(['setupItems', 'itemPlacements', 'creator', 'correctionRequester'])
            ->find($reportId);
    }

    /**
     * Create a new archival report
     */
    public function createReport(int $taskId, array $data): ArchivalReport
    {
        return DB::transaction(function () use ($taskId, $data) {
            // Extract related data
            $setupItems = $data['setup_items'] ?? [];
            $itemPlacements = $data['item_placements'] ?? [];
            unset($data['setup_items'], $data['item_placements']);

            // Transform project_scope array to JSON string if it's an array
            if (isset($data['project_scope']) && is_array($data['project_scope'])) {
                $data['project_scope'] = json_encode($data['project_scope']);
            }

            // Create main report
            $report = ArchivalReport::create([
                'enquiry_task_id' => $taskId,
                'created_by' => auth()->id(),
                ...$data,
            ]);

            // Create related items
            if (!empty($setupItems)) {
                foreach ($setupItems as $item) {
                    $report->setupItems()->create($item);
                }
            }

            if (!empty($itemPlacements)) {
                foreach ($itemPlacements as $placement) {
                    $report->itemPlacements()->create($placement);
                }
            }

            return $report->load(['setupItems', 'itemPlacements']);
        });
    }

    /**
     * Update an archival report
     */
    public function updateReport(int $reportId, array $data): ArchivalReport
    {
        return DB::transaction(function () use ($reportId, $data) {
            $report = ArchivalReport::findOrFail($reportId);

            // Extract related data
            $setupItems = $data['setup_items'] ?? null;
            $itemPlacements = $data['item_placements'] ?? null;
            unset($data['setup_items'], $data['item_placements']);

            // Transform project_scope array to JSON string if it's an array
            if (isset($data['project_scope']) && is_array($data['project_scope'])) {
                $data['project_scope'] = json_encode($data['project_scope']);
            }

            // Update main report
            $report->update($data);

            // Update setup items if provided (upsert — keep existing IDs)
            if ($setupItems !== null) {
                $incomingIds = collect($setupItems)->pluck('id')->filter()->values()->all();
                $report->setupItems()->whereNotIn('id', $incomingIds)->delete();

                foreach ($setupItems as $item) {
                    if (empty($item['deliverable_item'])) continue;
                    $id = $item['id'] ?? null;
                    unset($item['id']);
                    if ($id) {
                        $report->setupItems()->where('id', $id)->update($item);
                    } else {
                        $report->setupItems()->create($item);
                    }
                }
            }

            // Update item placements if provided (upsert — keep existing IDs)
            if ($itemPlacements !== null) {
                $incomingIds = collect($itemPlacements)->pluck('id')->filter()->values()->all();
                $report->itemPlacements()->whereNotIn('id', $incomingIds)->delete();

                foreach ($itemPlacements as $placement) {
                    if (empty($placement['section_area'])) continue;
                    $id = $placement['id'] ?? null;
                    unset($placement['id']);
                    if ($id) {
                        $report->itemPlacements()->where('id', $id)->update($placement);
                    } else {
                        $report->itemPlacements()->create($placement);
                    }
                }
            }

            return $report->fresh(['setupItems', 'itemPlacements']);
        });
    }

    /**
     * Delete an archival report
     */
    public function deleteReport(int $reportId): bool
    {
        $report = ArchivalReport::findOrFail($reportId);
        
        // Delete associated files
        if ($report->attachments) {
            foreach ($report->attachments as $attachment) {
                if (isset($attachment['path'])) {
                    Storage::disk('public')->delete($attachment['path']);
                }
            }
        }

        return $report->delete();
    }

    /**
     * Upload attachment to report
     */
    public function uploadAttachment(int $reportId, $file, string $category): array
    {
        $report = ArchivalReport::findOrFail($reportId);

        // Store file
        $path = $file->store("archival-reports/{$report->enquiry_task_id}", 'public');
        
        $attachmentData = [
            'id' => uniqid(),
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'category' => $category,
            'uploaded_at' => now()->toISOString(),
        ];

        // Add to attachments array
        $attachments = $report->attachments ?? [];
        $attachments[] = $attachmentData;
        
        $report->update(['attachments' => $attachments]);

        return [
            ...$attachmentData,
                'url' => storage_url($path),
        ];
    }

    /**
     * Delete attachment from report
     */
    public function deleteAttachment(int $reportId, string $attachmentId): bool
    {
        $report = ArchivalReport::findOrFail($reportId);
        
        $attachments = $report->attachments ?? [];
        $index = array_search($attachmentId, array_column($attachments, 'id'));
        
        if ($index === false) {
            return false;
        }

        // Delete file from storage
        if (isset($attachments[$index]['path'])) {
            Storage::disk('public')->delete($attachments[$index]['path']);
        }

        // Remove from array
        array_splice($attachments, $index, 1);
        $report->update(['attachments' => array_values($attachments)]);

        return true;
    }

    /**
     * Auto-populate report data from other tasks
     */
    public function autoPopulateData(int $taskId): array
    {
        $task = EnquiryTask::with([
            'enquiry.projectOfficer',
            'enquiry.client'
        ])->findOrFail($taskId);

        $data = [];

        // From Project Enquiry
        if ($task->enquiry) {
            // Client name from relationship (use company_name or full_name)
            if ($task->enquiry->client) {
                $data['client_name'] = $task->enquiry->client->company_name ?? $task->enquiry->client->full_name;
            }
            
            // Project code from job_number or enquiry_number
            $data['project_code'] = $task->enquiry->job_number ?? $task->enquiry->enquiry_number;
            
            // Site location from venue field
            $data['site_location'] = $task->enquiry->venue;
            
            // Project officer
            if ($task->enquiry->projectOfficer) {
                $data['project_officer'] = $task->enquiry->projectOfficer->name;
            }
            
            // Format dates to Y-m-d for HTML date inputs
            if ($task->enquiry->start_date) {
                $data['start_date'] = $task->enquiry->start_date->format('Y-m-d');
            }
            
            $endDate = $task->enquiry->end_date ?? $task->enquiry->expected_delivery_date;
            if ($endDate) {
                $data['end_date'] = $endDate->format('Y-m-d');
            }
            
            $projectScope = $task->enquiry->project_scope ?? $task->enquiry->description;
            if (is_array($projectScope)) {
                $projectScope = collect($projectScope)
                    ->map(fn ($item) => is_array($item) ? ($item['name'] ?? null) : $item)
                    ->filter()
                    ->implode("\n");
            }
            $data['project_scope'] = $projectScope;
            
            // --- From Materials Task ---
            try {
                $materialsTask = EnquiryTask::where('project_enquiry_id', $task->project_enquiry_id)
                    ->where('type', 'materials')
                    ->first();
                    
                if ($materialsTask) {
                    $materialsData = \App\Models\TaskMaterialsData::with('elements.materials')
                        ->where('enquiry_task_id', $materialsTask->id)
                        ->first();
                        
                    if ($materialsData && $materialsData->elements) {
                        $materialsList = [];
                        foreach ($materialsData->elements as $element) {
                            if (!$element->is_included) continue;
                            
                            foreach ($element->materials as $material) {
                                if ($material->is_included) {
                                    $materialsList[] = "- {$material->description}: {$material->quantity} {$material->unit}";
                                }
                            }
                        }
                        
                        if (!empty($materialsList)) {
                            $data['materials_used_in_production'] = implode("\n", $materialsList);
                        }

                        // --- Setup & Placements ---
                        $data['setup_items'] = [];
                        $data['item_placements'] = [];
                        
                        foreach ($materialsData->elements as $element) {
                            if (!$element->is_included) continue;
                            
                            // 1. Setup Item Allocation
                            $data['setup_items'][] = [
                                'deliverable_item' => $element->name,
                                'assigned_technician' => null,
                                'site_section' => $element->dimensions ? implode(' x ', $element->dimensions) : null,
                                'status' => 'pending',
                                'notes' => $element->notes
                            ];
                            
                            // 2. Item Placement Details
                            $data['item_placements'][] = [
                                'section_area' => $element->dimensions ? implode(' x ', $element->dimensions) : null,
                                'items_installed' => $element->name,
                                'placement_accuracy' => 'correct',
                                'observation' => $element->notes
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error('Archival Auto-populate: Materials fetch failed', ['error' => $e->getMessage()]);
            }
        }

        // Confirm only controls that belong to this project's configured
        // workflow and whose source tasks are genuinely complete. Existing
        // manual confirmations are not cleared when a source is still open.
        foreach ($this->getClosureContext($taskId)['required_checks'] ?? [] as $check) {
            if ($check['auto_verified']) {
                $data[$check['key']] = true;
            }
        }

        return $data;
    }

    /**
     * Change report status
     */
    public function changeStatus(int $reportId, string $status, $actor, ?string $correctionNotes = null): ArchivalReport
    {
        $report = ArchivalReport::findOrFail($reportId);
        $changes = ['status' => $status];

        if ($status === 'submitted') {
            $changes += [
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'project_officer_signature' => $actor->name,
                'project_officer_sign_date' => now()->toDateString(),
                'correction_resolved_at' => $report->correction_requested_at ? now() : $report->correction_resolved_at,
            ];
        }

        if ($status === 'returned') {
            $changes = [
                'status' => 'draft',
                'correction_requested_by' => $actor->id,
                'correction_requested_at' => now(),
                'correction_notes' => $correctionNotes,
                'correction_resolved_at' => null,
                'revision_number' => ((int) $report->revision_number) + 1,
            ];
        }

        if ($status === 'approved') {
            $changes += [
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'reviewed_by' => $actor->name,
                'reviewer_sign_date' => now()->toDateString(),
            ];
        }

        $report->update($changes);
        
        return $report->fresh();
    }

    /**
     * Get reports by status
     */
    public function getReportsByStatus(string $status): array
    {
        return ArchivalReport::byStatus($status)
            ->with(['enquiryTask', 'creator'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Analyze report and generate insights
     */
    public function analyzeReport(int $reportId): array
    {
        $report = ArchivalReport::findOrFail($reportId);
        
        $analysis = [
            'action_plan' => [],
            'root_causes' => [],
            'best_practices' => [],
            'lessons_learnt' => [],
            'recommendations' => [],
            'summary_score' => 0, // 0-100
        ];

        // --- 1. Root Cause Analysis ---
        if ($report->delays_occurred) {
            $analysis['root_causes'][] = "Project Delays: " . ($report->delay_reasons ?? 'Unspecified reasons');
            $analysis['lessons_learnt'][] = "Investigate and mitigate sources of delay: " . ($report->delay_reasons ?? 'N/A');
        }

        if ($report->procurement_challenges) {
            $analysis['root_causes'][] = "Procurement Issues: " . $report->procurement_challenges;
            $analysis['action_plan'][] = "Review supplier list and procurement lead times.";
        }

        if ($report->delivery_issues) {
            $analysis['root_causes'][] = "Delivery Complications: " . ($report->delivery_notes ?? 'Check delivery logs');
        }

        if ($report->client_satisfaction === 'unsatisfied') {
            $analysis['root_causes'][] = "Client Dissatisfaction: " . ($report->client_remarks ?? 'Review client feedback meetings');
        }

        // --- 2. Action Plan Generation ---
        // Checklist Compliance
        $checklistItems = [
            'checklist_site_survey_form' => 'Conduct mandatory site surveys before mobilization.',
            'checklist_qc_checklist' => 'Enforce Quality Control (QC) checklist completion prior to dispatch.',
            'checklist_client_feedback' => 'Ensure formal client feedback form is signed off.',
            'checklist_setup_setdown' => 'Verify setup/setdown protocols are followed.'
        ];

        foreach ($checklistItems as $field => $action) {
            if (!$report->$field) {
                $analysis['action_plan'][] = $action;
            }
        }

        // Performance based actions
        if ($report->cleanliness_rating === 'poor' || $report->cleanliness_rating === 'fair') {
            $analysis['action_plan'][] = "Retrain team on site cleanliness and waste disposal standards.";
        }

        if ($report->print_clarity_rating === 'poor' || $report->printworks_accuracy_rating === 'poor') {
            $analysis['action_plan'][] = "Audit print vendors or internal print quality assurance processes.";
        }

        // --- 3. Best Practices & Successes ---
        if ($report->site_organization === 'excellent') {
            $analysis['best_practices'][] = "Site Organization: Maintained high standards of site layout and safety.";
        }

        if ($report->team_coordination === 'good') {
            $analysis['best_practices'][] = "Team Coordination: Effective communication and role allocation observed.";
        }

        if ($report->delivered_on_schedule && !$report->delays_occurred) {
            $analysis['best_practices'][] = "Time Management: Project executed and delivered strictly on schedule.";
        }

        if ($report->client_rating === 'excellent' || $report->client_satisfaction === 'satisfied') {
            $analysis['best_practices'][] = "Client Relations: Strong client confidence and satisfaction achieved.";
        }

        // --- 4. Lessons Learnt ---
        if ($report->setup_aligned_to_schedule === false) {
            $analysis['lessons_learnt'][] = "Setup Schedule Mismatch: Future schedules should factor in buffer time for site realities.";
        }

        if ($report->items_sourced_externally && $report->procurement_challenges) {
            $analysis['lessons_learnt'][] = "External Sourcing Risk: Develop backup options for critical external items.";
        }

        // --- 5. Recommendations (General) ---
        if (!empty($report->recommendations_action_points)) {
            $analysis['recommendations'][] = $report->recommendations_action_points;
        }

        if (count($analysis['root_causes']) > 0) {
            $analysis['recommendations'][] = "Conduct a post-mortem meeting to address identified root causes.";
        }

        // --- 6. Scoring (Simple Heuristic) ---
        $score = 100;
        if ($report->delays_occurred) $score -= 10;
        if ($report->delivery_issues) $score -= 10;
        if ($report->client_satisfaction === 'unsatisfied') $score -= 20;
        if ($report->cleanliness_rating === 'poor') $score -= 10;
        if ($report->cleanliness_rating === 'fair') $score -= 5;
        if (!$report->checklist_qc_checklist) $score -= 10;

        $analysis['summary_score'] = max(0, $score);

        return $analysis;
    }
}
