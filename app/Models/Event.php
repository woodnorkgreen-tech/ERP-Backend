<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'event_name',
        'start_time',
        'end_time',
        'color',
        'is_all_day',
        'notes',
        'is_public',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'is_all_day' => 'boolean',
        'is_public' => 'boolean',
    ];

    protected $appends = ['created_by_name'];

    // Relationship with User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Accessor for created_by_name
    public function getCreatedByNameAttribute()
    {
        return $this->user ? $this->user->name : 'Unknown';
    }

    // Scope for public events
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    // Scope for user's personal events
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    // Scope for visible events (public + user's own)
    public function scopeVisibleTo($query, $userId)
    {
        return $query->where('is_public', true)
                     ->orWhere('user_id', $userId);
    }

    // Check if user can delete this event
    public function canBeDeletedBy($user)
    {
        // User owns the event OR user is HR (department_id = 4)
        return $this->user_id === $user->id || $user->department_id == 4;
    }

    // Check if user can edit this event
    public function canBeEditedBy($user)
    {
        return $this->user_id === $user->id || $user->department_id == 4;
    }
}