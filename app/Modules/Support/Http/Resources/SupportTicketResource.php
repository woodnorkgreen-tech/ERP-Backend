<?php

namespace App\Modules\Support\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canManage = $request->user()?->hasRole(['Super Admin', 'Admin']) || $request->user()?->can('support.manage');

        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'subject' => $this->subject,
            'description' => $this->description,
            'type' => $this->type,
            'category' => $this->category,
            'priority' => $this->priority,
            'status' => $this->status,
            'resolution' => $this->resolution,
            'reporter' => $this->whenLoaded('reporter', fn () => $this->reporter?->only(['id', 'name', 'email'])),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee?->only(['id', 'name', 'email'])),
            'resolver' => $this->whenLoaded('resolver', fn () => $this->resolver?->only(['id', 'name'])),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments
                ->when(!$canManage, fn ($attachments) => $attachments->filter(fn ($attachment) => !$attachment->message?->is_internal))
                ->values()
                ->map(fn ($attachment) => [
                'id' => $attachment->id,
                'name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
                'uploaded_by' => $attachment->uploaded_by,
                'created_at' => $attachment->created_at?->toISOString(),
            ])),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages
                ->when(!$canManage, fn ($messages) => $messages->where('is_internal', false))
                ->values()
                ->map(fn ($message) => [
                    'id' => $message->id,
                    'message' => $message->message,
                    'is_internal' => $message->is_internal,
                    'author' => $message->author?->only(['id', 'name']),
                    'attachments' => $message->relationLoaded('attachments') ? $message->attachments->map(fn ($attachment) => [
                        'id' => $attachment->id,
                        'name' => $attachment->original_name,
                        'mime_type' => $attachment->mime_type,
                        'size' => $attachment->size,
                        'created_at' => $attachment->created_at?->toISOString(),
                    ])->values() : [],
                    'created_at' => $message->created_at?->toISOString(),
                ])),
            'activities' => $this->when($canManage && $this->relationLoaded('activities'), fn () => $this->activities->map(fn ($activity) => [
                'id' => $activity->id,
                'action' => $activity->action,
                'changes' => $activity->changes,
                'actor' => $activity->actor?->only(['id', 'name']),
                'created_at' => $activity->created_at?->toISOString(),
            ])),
            'can_manage' => (bool) $canManage,
            'first_response_at' => $this->first_response_at?->toISOString(),
            'response_due_at' => $this->response_due_at?->toISOString(),
            'resolution_due_at' => $this->resolution_due_at?->toISOString(),
            'is_overdue' => !in_array($this->status, ['waiting_on_user', 'resolved', 'closed'], true)
                && $this->resolution_due_at?->isPast(),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'last_activity_at' => $this->last_activity_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
