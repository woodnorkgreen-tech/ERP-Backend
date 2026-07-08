<?php

namespace App\Modules\ClientService\Models;

use App\Models\ProjectEnquiry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'full_name',
        'company_name',
        'contact_person',
        'email',
        'phone',
        'alt_contact',
        'address',
        'city',
        'county',
        'postal_address',
        'customer_type',
        'lead_source',
        'preferred_contact',
        'industry',
        'registration_date',
        'status',
        'is_active',
    ];

    protected $casts = [
        'registration_date' => 'date',
        'customer_type' => 'string',
        'preferred_contact' => 'string',
        'status' => 'string',
        'is_active' => 'boolean',
    ];

    /**
     * Get the full name attribute (accessor for frontend compatibility)
     */
    public function getFullNameAttribute(): ?string
    {
        return $this->attributes['full_name'] ?? null;
    }

    /**
     * All project enquiries belonging to this client (past and current).
     */
    public function enquiries(): HasMany
    {
        return $this->hasMany(ProjectEnquiry::class, 'client_id');
    }

    /**
     * Logged interactions (calls, emails, notes, meetings) on this client's timeline.
     */
    public function interactions(): HasMany
    {
        return $this->hasMany(ClientInteraction::class, 'client_id');
    }
}
