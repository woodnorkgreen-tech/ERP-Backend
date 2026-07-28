<?php

namespace App\Modules\Support\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicketMessage extends Model
{
    protected $fillable = ['support_ticket_id', 'author_id', 'message', 'is_internal'];
    protected $casts = ['is_internal' => 'boolean'];
    public function ticket(): BelongsTo { return $this->belongsTo(SupportTicket::class, 'support_ticket_id'); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
    public function attachments(): HasMany { return $this->hasMany(SupportTicketAttachment::class, 'message_id'); }
}
