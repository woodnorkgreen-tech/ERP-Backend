<?php

namespace App\Modules\Design\Services;

use App\Models\User;
use App\Modules\Design\Models\DesignHandoff;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignJob;
use App\Modules\Design\Support\DesignAccess;
use App\Modules\Notifications\Services\NotificationService as CentralNotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DesignNotificationService
{
    public function notifyJobSynced(DesignJob $job): void
    {
        $job->loadMissing('enquiry.client');
        $users = $this->designLeadUsers();

        if ($users->isEmpty()) {
            return;
        }

        $this->send(
            type: 'design_job_synced',
            title: 'Design Job Synced',
            message: "Design job \"{$job->title}\" is ready for planning.",
            data: $this->jobPayload($job) + [
                'url' => "/design/jobs/{$job->id}",
            ],
            users: $users->all(),
        );
    }

    public function notifyJobReady(DesignJob $job): void
    {
        $job->loadMissing('enquiry.client');
        $users = $this->designLeadUsers();

        if ($users->isEmpty()) {
            return;
        }

        $this->send(
            type: 'design_job_ready',
            title: 'Design Job Ready',
            message: "Design job \"{$job->title}\" is ready to start.",
            data: $this->jobPayload($job) + [
                'url' => "/design/jobs/{$job->id}",
            ],
            users: $users->all(),
        );
    }

    public function notifyItemAssigned(DesignItem $item): void
    {
        if (!$item->assigned_to) {
            return;
        }

        $item->loadMissing(['job.enquiry.client', 'type', 'assignedUser']);

        if (!$item->assignedUser) {
            return;
        }

        $stream = $item->stream === DesignItem::STREAM_GRAPHIC ? 'Graphic Design' : 'Structural Design';

        $this->send(
            type: 'design_item_assigned',
            title: "{$stream} Assigned",
            message: "\"{$item->title}\" has been assigned to you.",
            data: $this->itemPayload($item) + [
                'url' => $this->itemUrl($item),
            ],
            users: [$item->assignedUser],
        );
    }

    public function notifyItemReady(DesignItem $item): void
    {
        $item->loadMissing(['job.enquiry.client', 'type']);
        $users = $this->designLeadUsers();

        if ($users->isEmpty()) {
            return;
        }

        $label = $item->stream === DesignItem::STREAM_GRAPHIC ? 'Print Ready' : 'Production Ready';

        $this->send(
            type: 'design_item_ready',
            title: "Design Item {$label}",
            message: "\"{$item->title}\" is now {$label}.",
            data: $this->itemPayload($item) + [
                'url' => $this->itemUrl($item),
            ],
            users: $users->all(),
        );
    }

    public function notifyHandoffRejected(DesignHandoff $handoff): void
    {
        $handoff->loadMissing(['item.job.enquiry.client', 'item.type', 'item.assignedUser']);
        $item = $handoff->item;

        if (!$item) {
            return;
        }

        $users = $this->designLeadUsers();
        if ($item->assignedUser) {
            $users = $users->push($item->assignedUser);
        }

        $users = $users->unique('id')->values();
        if ($users->isEmpty()) {
            return;
        }

        $this->send(
            type: 'design_handoff_rejected',
            title: 'Printing Rejected Artwork',
            message: "\"{$item->title}\" was rejected by {$handoff->target_module}: {$handoff->rejection_reason}",
            urgency: 'warning',
            data: $this->itemPayload($item) + [
                'handoff_id' => $handoff->id,
                'target_module' => $handoff->target_module,
                'rejection_reason' => $handoff->rejection_reason,
                'url' => $this->itemUrl($item),
            ],
            users: $users->all(),
        );
    }

    private function send(
        string $type,
        string $title,
        string $message,
        array $data,
        array $users,
        string $urgency = 'info',
    ): void {
        try {
            CentralNotificationService::send(
                type: $type,
                title: $title,
                message: $message,
                module: 'design',
                urgency: $urgency,
                data: $data,
                users: $users,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send Design notification: ' . $e->getMessage(), [
                'type' => $type,
                'data' => $data,
            ]);
        }
    }

    private function designLeadUsers(): Collection
    {
        return User::query()
            ->active()
            ->with(['department', 'employee.department', 'roles'])
            ->get()
            ->filter(fn (User $user) => DesignAccess::userCanAccessLeadViews($user))
            ->values();
    }

    private function jobPayload(DesignJob $job): array
    {
        return [
            'design_job_id' => $job->id,
            'project_enquiry_id' => $job->project_enquiry_id,
            'enquiry_id' => $job->project_enquiry_id,
            'job_number' => $job->job_number,
            'design_job_title' => $job->title,
            'client_name' => $job->enquiry?->client?->full_name ?? $job->enquiry?->client?->name,
        ];
    }

    private function itemPayload(DesignItem $item): array
    {
        return $this->jobPayload($item->job) + [
            'design_item_id' => $item->id,
            'design_item_title' => $item->title,
            'stream' => $item->stream,
            'status' => $item->status,
            'design_type' => $item->type?->name,
        ];
    }

    private function itemUrl(DesignItem $item): string
    {
        return "/design/{$item->stream}?design_job_id={$item->design_job_id}&highlight_item={$item->id}";
    }
}
