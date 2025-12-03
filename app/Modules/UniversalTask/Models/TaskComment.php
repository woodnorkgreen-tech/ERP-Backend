<?php

namespace App\Modules\UniversalTask\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskComment extends Model
{
    use HasFactory;

    protected $table = 'task_comments';

    protected $fillable = [
        'task_id',
        'user_id',
        'parent_comment_id',
        'content',
    ];

    /**
     * Get the task that owns the comment.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the user who created the comment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parent comment (for threaded comments).
     */
    public function parentComment(): BelongsTo
    {
        return $this->belongsTo(TaskComment::class, 'parent_comment_id');
    }

    /**
     * Get the replies to this comment.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'parent_comment_id');
    }

    /**
     * Parse the content and extract mentioned usernames.
     * Returns an array of usernames mentioned in the format @username.
     *
     * @return array
     */
    public function getMentionedUsernames(): array
    {
        preg_match_all('/@(\w+)/', $this->content, $matches);
        return $matches[1] ?? [];
    }

    /**
     * Get the User models for all mentioned users.
     * Returns a collection of User models that were mentioned in the comment.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMentionedUsers()
    {
        $usernames = $this->getMentionedUsernames();
        
        if (empty($usernames)) {
            return collect();
        }

        return User::whereIn('name', $usernames)->get();
    }
}
