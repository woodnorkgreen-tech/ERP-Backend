<?php

namespace App\Modules\Support\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    public const TYPES = ['bug', 'improvement', 'support'];
    public const CATEGORIES = ['erp', 'access', 'device', 'network', 'email', 'other'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const STATUSES = ['open', 'assigned', 'in_progress', 'waiting_on_user', 'resolved', 'closed'];

    protected $fillable = [
        'ticket_number', 'reporter_id', 'assigned_to', 'subject', 'description', 'type', 'category',
        'priority', 'status', 'resolution', 'resolved_at', 'resolved_by', 'last_activity_at',
        'first_response_at', 'response_due_at', 'resolution_due_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'first_response_at' => 'datetime',
        'response_due_at' => 'datetime',
        'resolution_due_at' => 'datetime',
    ];

    public function reporter(): BelongsTo { return $this->belongsTo(User::class, 'reporter_id'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function resolver(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }
    public function messages(): HasMany { return $this->hasMany(SupportTicketMessage::class); }
    public function attachments(): HasMany { return $this->hasMany(SupportTicketAttachment::class); }
    public function activities(): HasMany { return $this->hasMany(SupportTicketActivity::class); }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('support.manage') || $user->hasRole(['Super Admin', 'Admin'])
            ? $query
            : $query->where('reporter_id', $user->id);
    }
}
