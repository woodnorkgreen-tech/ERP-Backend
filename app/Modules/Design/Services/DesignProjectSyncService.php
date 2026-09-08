<?php

namespace App\Modules\Design\Services;

use App\Models\ProjectEnquiry;
use App\Modules\Design\Models\DesignJob;
use Illuminate\Support\Carbon;

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
     * newly created vs. already in sync, since `updateOrCreate` touches
     * existing rows too.
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

        return DesignJob::updateOrCreate(
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
