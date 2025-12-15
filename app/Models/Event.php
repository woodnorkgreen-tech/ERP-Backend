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
        'is_minute',
        'agenda',
        'recipient_type',
        'attendees',
        'department_ids',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'is_all_day' => 'boolean',
        'is_public' => 'boolean',
        'is_minute' => 'boolean',
        'attendees' => 'array',
        'department_ids' => 'array',
    ];

    protected $appends = ['created_by_name'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getCreatedByNameAttribute()
    {
        return $this->user ? $this->user->name : 'Unknown';
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

  public function scopeVisibleTo($query, $userId)
{
    $user = \App\Models\User::find($userId);
    
    return $query->where(function($q) use ($userId, $user) {
        $q->where('is_public', true)
          ->orWhere('user_id', $userId)
          ->orWhere(function($query) use ($userId, $user) {
              // Meeting minutes for everyone
              $query->where('is_minute', true)
                    ->where('recipient_type', 'all');
          })
          ->orWhere(function($query) use ($userId) {
              // Meeting minutes sent to specific employees
              $query->where('is_minute', true)
                    ->where('recipient_type', 'employee')
                    ->whereJsonContains('attendees', $userId);
          })
          ->orWhere(function($query) use ($user) {
              // Meeting minutes sent to user's department
              $query->where('is_minute', true)
                    ->where('recipient_type', 'department')
                    ->whereJsonContains('department_ids', (int)$user->department_id);
          });
    });
}
    public function canBeDeletedBy($user)
    {
        return $this->user_id === $user->id || $user->hasRole('HR');
    }

    public function canBeEditedBy($user)
    {
        return $this->user_id === $user->id || $user->hasRole('HR');
    }
}