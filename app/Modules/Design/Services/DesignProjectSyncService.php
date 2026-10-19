<?php

namespace App\Modules\Design\Services;

use App\Models\ProjectEnquiry;
use App\Models\ProjectElement;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignUpdate;
use App\Modules\Design\Models\DesignJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DesignProjectSyncService
{
    public const UPCOMING_DAYS = 7;

    public const ALLOWED_SYNC_WINDOWS = [7, 14, 30, 90];

    public function __construct(private readonly DesignNotificationService $notifications)
    {
    }

    /**
     * Scan all open enquiries due within the given window and sync any that
     * qualify. Used by both the scheduled command (default window) and the
     * manual sync-window picker (custom window). Reports how many jobs were
     * newly created vs. already in sync.
     *
     * @return array{created: int, total: int, days: int}
     */
    public function syncAllUpcoming(?int $days = null): array
    {
        $days ??= self::UPCOMING_DAYS;

        $enquiries = ProjectEnquiry::query()
            ->whereNotNull('expected_delivery_date')
            ->where('status', '!=', 'cancelled')
            ->whereDate('expected_delivery_date', '>=', now()->startOfDay())
            ->whereDate('expected_delivery_date', '<=', now()->startOfDay()->addDays($days))
            ->with(['client', 'project', 'deliverables', 'projectOfficer', 'enquiryTasks'])
            ->get();

        $created = 0;
        $total = 0;
        foreach ($enquiries as $enquiry) {
            $job = $this->syncUpcoming($enquiry, $days);
            if ($job) {
                $total++;
                if ($job->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        return ['created' => $created, 'total' => $total, 'days' => $days];
    }

    public function syncUpcoming(ProjectEnquiry $enquiry, ?int $days = null): ?DesignJob
    {
        $enquiry->loadMissing(['client', 'project', 'deliverables', 'projectOfficer', 'enquiryTasks']);

        if (!$this->hasDesignWorkflow($enquiry) || !$this->isDueInUpcomingWindow($enquiry, $days)) {
            return null;
        }

        $job = $this->syncFromEnquiry($enquiry);

        if ($job->wasRecentlyCreated) {
            $this->notifications->notifyJobSynced($job->loadMissing(['enquiry.client']));
        }

        return $job;
    }

    public function syncFromEnquiry(ProjectEnquiry $enquiry): DesignJob
    {
        $enquiry->loadMissing(['client', 'project', 'deliverables', 'projectOfficer']);

        return DB::transaction(function () use ($enquiry) {
            $enquiry = ProjectEnquiry::query()->whereKey($enquiry->id)->lockForUpdate()->firstOrFail();
            $enquiry->load(['client', 'project', 'deliverables', 'projectOfficer']);
            $job = DesignJob::firstOrCreate(
                ['project_enquiry_id' => $enquiry->id],
                [
                    'project_id' => $enquiry->project?->id,
                    'client_id' => $enquiry->client_id,
                    'job_number' => $enquiry->job_number,
                    'title' => $enquiry->title ?? "Design Job #{$enquiry->id}",
                    'source_type' => 'project_scope',
                    'sync_origin' => 'automatic',
                    'auto_synced_at' => now(),
                    'priority' => $this->mapPriority($enquiry->priority),
                    'due_date' => $enquiry->expected_delivery_date,
                    'status' => 'pending',
                    'updated_by' => auth()->id(),
                    'created_by' => auth()->id(),
                ]
            );
            if (! $job->wasRecentlyCreated) {
                $job->update([
                    'project_id' => $enquiry->project?->id,
                    'client_id' => $enquiry->client_id,
                    'job_number' => $enquiry->job_number,
                    'title' => $enquiry->title ?? "Design Job #{$enquiry->id}",
                    'auto_synced_at' => now(),
                    'priority' => $this->mapPriority($enquiry->priority),
                    'due_date' => $enquiry->expected_delivery_date,
                    'updated_by' => auth()->id(),
                ]);
            }
            $result = $this->syncItemsForJob($job);
            foreach ($result['assigned_by_stream'] as $stream => $count) {
                $designerId = $this->mainDesignerId($job, $stream);
                if ($count && $designerId) {
                    DB::afterCommit(fn () => $this->notifications->notifyProjectTasksAssigned($job, $stream, $designerId, $count));
                }
            }

            return $job;
        });
    }

    /** Ensure exactly one generated primary task per current project element and stream. */
    public function syncItemsForJob(DesignJob $job, bool $dryRun = false): array
    {
        if (! $job->project_enquiry_id) return ['created' => 0, 'adopted' => 0, 'ambiguous' => 0, 'ambiguous_keys' => [], 'assigned_by_stream' => ['graphic' => 0, 'structural' => 0]];

        $result = ['created' => 0, 'adopted' => 0, 'ambiguous' => 0, 'ambiguous_keys' => [], 'assigned_by_stream' => ['graphic' => 0, 'structural' => 0]];
        $elements = ProjectElement::query()->where('enquiry_id', $job->project_enquiry_id)->get();
        foreach ($elements as $element) {
            foreach ([DesignItem::STREAM_GRAPHIC, DesignItem::STREAM_STRUCTURAL] as $stream) {
                $key = "{$job->id}:{$element->id}:{$stream}";
                $item = DesignItem::query()->where('generated_scope_key', $key)->first();
                if (! $item) {
                    $candidates = DesignItem::query()
                        ->where('design_job_id', $job->id)
                        ->where('project_deliverable_id', $element->id)
                        ->where('stream', $stream)
                        ->whereNull('generated_scope_key')
                        ->whereNull('redesign_of_item_id')
                        ->whereNull('redesign_of_print_job_id')
                        ->limit(2)->get();
                    if ($candidates->count() > 1) {
                        $result['ambiguous']++;
                        $result['ambiguous_keys'][] = $key;
                        if (! $dryRun) {
                            Log::warning('Ambiguous primary Design task for project element', [
                                'design_job_id' => $job->id, 'project_deliverable_id' => $element->id, 'stream' => $stream,
                            ]);
                        }
                        continue;
                    }
                    if ($dryRun) {
                        $result[$candidates->count() === 1 ? 'adopted' : 'created']++;
                        continue;
                    }
                    if ($candidates->count() === 1) {
                        $item = $candidates->first();
                        $item->generated_scope_key = $key;
                        $item->last_synced_element_title = $item->title === $element->name ? $element->name : null;
                        if ($item->assigned_to === null && ! $item->isOutsourced()) {
                            $item->assignment_source = 'project_default';
                            $item->assigned_to = $this->mainDesignerId($job, $stream);
                            if ($item->assigned_to) $result['assigned_by_stream'][$stream]++;
                        }
                        $item->save();
                        $result['adopted']++;
                    } else {
                        $item = DesignItem::firstOrCreate(['generated_scope_key' => $key], [
                            'design_job_id' => $job->id,
                            'project_deliverable_id' => $element->id,
                            'stream' => $stream,
                            'title' => $element->name,
                            'last_synced_element_title' => $element->name,
                            'assignment_source' => 'project_default',
                            'assigned_to' => $this->mainDesignerId($job, $stream),
                            'status' => 'pending',
                            'created_by' => auth()->id(),
                            'updated_by' => auth()->id(),
                        ]);
                        if ($item->wasRecentlyCreated) {
                            $result['created']++;
                            if ($item->assigned_to) $result['assigned_by_stream'][$stream]++;
                        }
                    }
                }
                if ($dryRun) continue;
                if ($item->source_element_archived_at !== null) {
                    $item->update(['source_element_archived_at' => null]);
                    DesignUpdate::create(['design_item_id' => $item->id, 'note' => 'Source project element restored.', 'created_by' => auth()->id()]);
                }
                if ($item->last_synced_element_title && $item->title === $item->last_synced_element_title && $item->title !== $element->name) {
                    $item->update(['title' => $element->name, 'last_synced_element_title' => $element->name]);
                }
            }
        }

        if (! $dryRun) {
            $activeIds = $elements->pluck('id')->all();
            DesignItem::query()->where('design_job_id', $job->id)->whereNotNull('generated_scope_key')
                ->whereNotNull('project_deliverable_id')->whereNotIn('project_deliverable_id', $activeIds)
                ->whereNull('source_element_archived_at')->get()->each(function (DesignItem $item) {
                    $item->update(['source_element_archived_at' => now()]);
                    DesignUpdate::create(['design_item_id' => $item->id, 'note' => 'Source project element was removed or archived; Design lead review required.', 'created_by' => auth()->id()]);
                });
        }

        return $result;
    }

    private function mainDesignerId(DesignJob $job, string $stream): ?int
    {
        return $stream === DesignItem::STREAM_GRAPHIC
            ? $job->graphic_main_designer_id : $job->structural_main_designer_id;
    }

    public function hasDesignWorkflow(ProjectEnquiry $enquiry): bool
    {
        $selectedTasks = $enquiry->selected_workflow_tasks;
        if (!is_array($selectedTasks)) {
            $selectedTasks = json_decode((string) $selectedTasks, true) ?: [];
        }

        if (in_array('design', $selectedTasks, true)) {
            return true;
        }

        $preset = $enquiry->workflow_preset_type;
        $presetTasks = $preset ? config("enquiry_workflow.task_presets.{$preset}.tasks", []) : [];
        if (in_array('design', $presetTasks, true)) {
            return true;
        }

        return $enquiry->enquiryTasks->contains('type', 'design');
    }

    public function isDueInUpcomingWindow(ProjectEnquiry $enquiry, ?int $days = null): bool
    {
        if (!$enquiry->expected_delivery_date) {
            return false;
        }

        $dueDate = Carbon::parse($enquiry->expected_delivery_date)->startOfDay();

        return $dueDate->betweenIncluded(
            now()->startOfDay(),
            now()->startOfDay()->addDays($days ?? self::UPCOMING_DAYS)
        );
    }

    public function mapPriority(?string $priority): string
    {
        return match ($priority) {
            'low', 'high', 'urgent' => $priority,
            default => 'normal',
        };
    }
}
