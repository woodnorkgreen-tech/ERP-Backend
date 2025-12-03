<?php

namespace App\Modules\UniversalTask\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAttachment extends Model
{
    use HasFactory;

    protected $table = 'task_attachments';

    protected $fillable = [
        'task_id',
        'uploaded_by',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
        'version',
        'description',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'version' => 'integer',
    ];

    /**
     * Get the task that owns the attachment.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the user who uploaded the attachment.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get the next version number for a file with the same name on the same task.
     * This method should be called before creating a new attachment to determine
     * the appropriate version number.
     *
     * @param int $taskId The task ID
     * @param string $fileName The file name
     * @return int The next version number
     */
    public static function getNextVersion(int $taskId, string $fileName): int
    {
        $maxVersion = static::where('task_id', $taskId)
            ->where('file_name', $fileName)
            ->max('version');

        return $maxVersion ? $maxVersion + 1 : 1;
    }

    /**
     * Get all versions of this file (same task_id and file_name).
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllVersions()
    {
        return static::where('task_id', $this->task_id)
            ->where('file_name', $this->file_name)
            ->orderBy('version', 'desc')
            ->get();
    }

    /**
     * Get the latest version of this file.
     *
     * @return TaskAttachment|null
     */
    public function getLatestVersion(): ?TaskAttachment
    {
        return static::where('task_id', $this->task_id)
            ->where('file_name', $this->file_name)
            ->orderBy('version', 'desc')
            ->first();
    }

    /**
     * Check if this is the latest version of the file.
     *
     * @return bool
     */
    public function isLatestVersion(): bool
    {
        $latest = $this->getLatestVersion();
        return $latest && $latest->id === $this->id;
    }

    /**
     * Get a human-readable file size.
     *
     * @return string
     */
    public function getFormattedFileSize(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Boot the model and register model events.
     */
    protected static function boot()
    {
        parent::boot();

        // Automatically set version number before creating
        static::creating(function ($attachment) {
            if (!$attachment->version) {
                $attachment->version = static::getNextVersion(
                    $attachment->task_id,
                    $attachment->file_name
                );
            }
        });
    }
}
