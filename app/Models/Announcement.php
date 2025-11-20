<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Department;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_user_id',
        'to_employee_id',
        'to_department_id',
        'message',
        'type'
    ];

    protected $appends = [
        'from_name',
        'to_name'
    ];

    /**
     * Get the user who created the announcement.
     */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /**
     * Get the target employee (if type is 'employee').
     */
    public function toEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    /**
     * Get the target department (if type is 'department').
     */
    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }

    /**
     * Get users who have read this announcement.
     */
    public function readByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'announcement_reads')
            ->withTimestamps()
            ->withPivot('read_at');
    }

    /**
     * Get the sender's name.
     */
    public function getFromNameAttribute(): string
    {
        return $this->fromUser?->name ?? 'Unknown';
    }

    /**
     * Get the recipient's name.
     */
    public function getToNameAttribute(): string
    {
        if ($this->type === 'employee') {
            return $this->toEmployee?->name ?? 'Unknown Employee';
        }
        
        if ($this->type === 'department') {
            return $this->toDepartment?->name ?? 'Unknown Department';
        }

        return 'Unknown';
    }

    /**
     * Check if a specific user has read this announcement.
     */
    public function isReadBy($userId): bool
    {
        return $this->readByUsers()->where('user_id', $userId)->exists();
    }

    /**
     * Mark as read by a user.
     */
    public function markAsReadBy($userId): void
    {
        if (!$this->isReadBy($userId)) {
            $this->readByUsers()->attach($userId, ['read_at' => now()]);
        }
    }

    /**
     * Scope to get announcements for a specific user.
     */
   public function scopeForUser($query, $user)
{
    return $query->where(function ($q) use ($user) {
        // Announcements sent BY this user (HR can see what they sent)
        $q->where('from_user_id', $user->id)
        
        // OR Direct employee announcements - match by employee_id in users table
        ->orWhere(function ($subQ) use ($user) {
            $subQ->where('type', 'employee')
                ->where('to_employee_id', $user->employee_id);
        })
        
        // OR Department announcements - match by department_id in users table
        ->orWhere(function ($subQ) use ($user) {
            $subQ->where('type', 'department')
                ->where('to_department_id', $user->department_id);
        });
    });
}

    /**
     * Scope to get unread announcements for a user.
     */
    public function scopeUnreadForUser($query, $userId)
    {
        return $query->whereDoesntHave('readByUsers', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        });
    }
    public function hasBeenReadByRecipients(): bool
{
    return $this->readByUsers()
        ->where('user_id', '!=', $this->from_user_id)
        ->exists();
}
}