<?php

namespace App\Modules\Support\Services;

use App\Models\User;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SupportTicketService
{
    private const TRANSITIONS = [
        'open' => ['assigned', 'in_progress', 'resolved', 'closed'],
        'assigned' => ['open', 'in_progress', 'waiting_on_user', 'resolved', 'closed'],
        'in_progress' => ['waiting_on_user', 'resolved', 'closed'],
        'waiting_on_user' => ['in_progress', 'resolved', 'closed'],
        'resolved' => ['in_progress', 'closed'],
        'closed' => ['in_progress'],
    ];

    public function create(User $reporter, array $data, array $files = []): SupportTicket
    {
        $ticketId = null;
        try {
            $ticket = DB::transaction(function () use ($reporter, $data, $files, &$ticketId) {
                $ticket = SupportTicket::create([
                    ...Arr::only($data, ['subject', 'description', 'type', 'category', 'priority']),
                    'reporter_id' => $reporter->id,
                    'priority' => $data['priority'] ?? 'normal',
                    'status' => 'open',
                    'last_activity_at' => now(),
                ]);
                $ticketId = $ticket->id;
                $ticket->update(['ticket_number' => sprintf('ICT-%s-%06d', now()->format('Y'), $ticket->id)]);

                foreach ($files as $file) {
                    $this->storeAttachment($ticket, $reporter, $file);
                }

                $ticket->activities()->create([
                    'actor_id' => $reporter->id,
                    'action' => 'created',
                    'changes' => ['status' => 'open'],
                ]);

                return $ticket;
            });
        } catch (\Throwable $exception) {
            if ($ticketId) Storage::disk('local')->deleteDirectory("support-tickets/{$ticketId}");
            throw $exception;
        }

        NotificationService::send(
            type: 'support_ticket_received',
            title: "Ticket {$ticket->ticket_number} received",
            message: 'Your ICT support request was submitted successfully.',
            module: 'support',
            data: ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number, 'route' => "/support/tickets/{$ticket->id}"],
            users: [$reporter->id],
        );
        NotificationService::send(
            type: 'support_ticket_created',
            title: "New ICT ticket {$ticket->ticket_number}",
            message: $ticket->subject,
            module: 'support',
            urgency: in_array($ticket->priority, ['high', 'urgent'], true) ? 'warning' : 'info',
            data: ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number, 'route' => "/support/admin/tickets/{$ticket->id}"],
            role: ['Super Admin', 'Admin'],
            permission: 'support.manage',
        );

        return $ticket;
    }

    public function update(SupportTicket $ticket, User $actor, array $data): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $actor, $data) {
            $isDeskAdmin = $actor->hasRole(['Super Admin', 'Admin']);
            if (array_key_exists('assigned_to', $data) && !$isDeskAdmin) {
                $claimingOwnUnassignedTicket = !$ticket->assigned_to && (int) $data['assigned_to'] === $actor->id;
                if (!$claimingOwnUnassignedTicket) {
                    throw ValidationException::withMessages([
                        'assigned_to' => ['ICT agents may claim unassigned tickets. Only an administrator can reassign or unassign work.'],
                    ]);
                }
            }

            if (!empty($data['assigned_to'])) {
                $assignee = User::query()->active()->find($data['assigned_to']);
                if (!$assignee || (!$assignee->hasRole(['Super Admin', 'Admin']) && !$assignee->can('support.manage'))) {
                    throw ValidationException::withMessages(['assigned_to' => ['Tickets can only be assigned to an authorized ICT support administrator.']]);
                }
            }

            $before = $ticket->only(['assigned_to', 'status', 'priority', 'category', 'resolution']);
            $targetStatus = $data['status'] ?? $ticket->status;

            if ($targetStatus !== $ticket->status && !in_array($targetStatus, self::TRANSITIONS[$ticket->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => ["A ticket cannot move from {$ticket->status} to {$targetStatus}."]]);
            }

            if (array_key_exists('assigned_to', $data) && $data['assigned_to'] && $ticket->status === 'open' && !isset($data['status'])) {
                $data['status'] = 'assigned';
            }
            if (array_key_exists('assigned_to', $data) && !$data['assigned_to'] && $ticket->status === 'assigned' && !isset($data['status'])) {
                $data['status'] = 'open';
            }

            if (in_array($data['status'] ?? '', ['resolved', 'closed'], true)) {
                $data['resolved_at'] = now();
                $data['resolved_by'] = $actor->id;
            } elseif (($data['status'] ?? null) === 'in_progress' && in_array($ticket->status, ['resolved', 'closed'], true)) {
                $data['resolved_at'] = null;
                $data['resolved_by'] = null;
                $data['resolution'] = null;
            }

            $ticket->fill(Arr::only($data, ['assigned_to', 'status', 'priority', 'category', 'resolution', 'resolved_at', 'resolved_by']));
            $ticket->last_activity_at = now();
            $ticket->save();

            $changes = collect($ticket->only(array_keys($before)))
                ->filter(fn ($value, $key) => (string) $value !== (string) $before[$key])
                ->map(fn ($value, $key) => ['from' => $before[$key], 'to' => $value])
                ->all();

            if ($changes !== []) {
                $ticket->activities()->create(['actor_id' => $actor->id, 'action' => 'updated', 'changes' => $changes]);
            }

            NotificationService::send(
                type: 'support_ticket_updated',
                title: "Ticket {$ticket->ticket_number} updated",
                message: $ticket->status === 'resolved' ? 'Your support request has been resolved.' : "Status: " . str_replace('_', ' ', $ticket->status),
                module: 'support',
                urgency: $ticket->status === 'resolved' ? 'success' : 'info',
                data: ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number, 'route' => "/support/tickets/{$ticket->id}"],
                users: [$ticket->reporter_id],
            );

            if (isset($changes['assigned_to']) && $ticket->assigned_to) {
                NotificationService::send(
                    type: 'support_ticket_assigned',
                    title: "Ticket {$ticket->ticket_number} assigned to you",
                    message: $ticket->subject,
                    module: 'support',
                    data: ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number, 'route' => "/support/admin/tickets/{$ticket->id}"],
                    users: [$ticket->assigned_to],
                );
            }

            return $ticket;
        });
    }

    public function reply(
        SupportTicket $ticket,
        User $author,
        string $message,
        bool $internal = false,
        string $action = 'keep',
        array $files = [],
    ): SupportTicket
    {
        if ($ticket->attachments()->count() + count($files) > 10) {
            throw ValidationException::withMessages(['attachments' => ['A ticket can contain at most 10 attachments.']]);
        }

        $storedPaths = [];
        try {
            return DB::transaction(function () use ($ticket, $author, $message, $internal, $action, $files, &$storedPaths) {
                $reply = $ticket->messages()->create(['author_id' => $author->id, 'message' => $message, 'is_internal' => $internal]);

                foreach ($files as $file) {
                    $attachment = $this->storeAttachment($ticket, $author, $file, $reply->id);
                    $storedPaths[] = $attachment->path;
                }

                $autoClaimed = !$internal
                    && !$ticket->assigned_to
                    && $author->can('support.manage')
                    && !$author->hasRole(['Super Admin', 'Admin']);
                if ($autoClaimed) {
                    $ticket->update([
                        'assigned_to' => $author->id,
                        'status' => $action === 'keep' ? 'in_progress' : $ticket->status,
                    ]);
                }

                if (!$internal && $author->id === $ticket->reporter_id && in_array($ticket->status, ['waiting_on_user', 'resolved'], true)) {
                    $ticket->update(['status' => 'in_progress', 'resolved_at' => null, 'resolved_by' => null, 'resolution' => null]);
                } elseif (!$internal && $action === 'waiting_on_user') {
                    $ticket->update(['status' => 'waiting_on_user']);
                } elseif (!$internal && $action === 'resolved') {
                    $ticket->update([
                        'status' => 'resolved',
                        'resolution' => $message,
                        'resolved_at' => now(),
                        'resolved_by' => $author->id,
                    ]);
                }

                $ticket->update(['last_activity_at' => now()]);
                $ticket->activities()->create([
                    'actor_id' => $author->id,
                    'action' => $internal ? 'internal_note_added' : 'reply_added',
                    'changes' => array_filter(['action' => $action !== 'keep' ? $action : null, 'auto_claimed' => $autoClaimed ?: null, 'attachments' => count($files) ?: null]),
                ]);

                if (!$internal) {
                    $recipientIds = $author->id === $ticket->reporter_id
                        ? array_values(array_filter([$ticket->assigned_to]))
                        : [$ticket->reporter_id];
                    $notifyQueue = $author->id === $ticket->reporter_id && !$ticket->assigned_to;
                    if ($recipientIds !== [] || $notifyQueue) {
                        NotificationService::send(
                            type: 'support_ticket_reply',
                            title: "New reply on {$ticket->ticket_number}",
                            message: mb_strimwidth($message, 0, 140, '…'),
                            module: 'support',
                            urgency: $action === 'resolved' ? 'success' : 'info',
                            data: ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number, 'route' => $author->id === $ticket->reporter_id ? "/support/admin/tickets/{$ticket->id}" : "/support/tickets/{$ticket->id}"],
                            users: $recipientIds,
                            role: $notifyQueue ? ['Super Admin', 'Admin'] : [],
                            permission: $notifyQueue ? 'support.manage' : [],
                        );
                    }
                }

                return $ticket;
            });
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function addAttachment(SupportTicket $ticket, User $user, UploadedFile $file): SupportTicketAttachment
    {
        return DB::transaction(function () use ($ticket, $user, $file) {
            $attachment = $this->storeAttachment($ticket, $user, $file);
            $ticket->update(['last_activity_at' => now()]);
            $ticket->activities()->create(['actor_id' => $user->id, 'action' => 'attachment_added', 'changes' => ['name' => $file->getClientOriginalName()]]);
            return $attachment;
        });
    }

    private function storeAttachment(SupportTicket $ticket, User $user, UploadedFile $file, ?int $messageId = null): SupportTicketAttachment
    {
        $disk = 'local';
        $path = $file->store("support-tickets/{$ticket->id}", $disk);
        try {
            return $ticket->attachments()->create([
                'uploaded_by' => $user->id,
                'message_id' => $messageId,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
            ]);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }
}
