<?php

namespace App\Modules\ClientService\Models;

use App\Models\ProjectEnquiry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientInteraction extends Model
{
    use HasFactory;

    /** Allowed interaction channels. */
    public const TYPES = ['call', 'email', 'note', 'meeting'];

    protected $fillable = [
        'client_id',
        'enquiry_id',
        'user_id',
        'type',
        'subject',
        'body',
        'interaction_at',
    ];

    protected $casts = [
        'interaction_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ProjectEnquiry::class, 'enquiry_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
