<?php

namespace App\Traits;

use App\Models\ActionLog;
use Illuminate\Support\Facades\Auth;

trait LogsActions
{
    /**
     * Boot the trait.
     */
    public static function bootLogsActions()
    {
        static::created(function ($model) {
            static::logAction($model, 'created');
        });

        static::updated(function ($model) {
            static::logAction($model, 'updated');
        });

        static::deleted(function ($model) {
            static::logAction($model, 'deleted');
        });
    }

    /**
     * Log the action.
     */
    protected static function logAction($model, $action)
    {
        // Don't log if we explicitly disable it for this operation
        if (isset($model->disableLogging) && $model->disableLogging) {
            return;
        }
        
        $original = null;
        $changes = null;

        if ($action === 'updated') {
            $original = $model->getOriginal();
            $changes = $model->getChanges();
            
            // Filter out timestamps from changes if they are the only things that changed
            $changes = array_filter($changes, function($key) {
                return !in_array($key, ['updated_at', 'created_at']);
            }, ARRAY_FILTER_USE_KEY);

            if (empty($changes)) {
                return; // Nothing meaningful changed
            }
        } elseif ($action === 'created') {
            $changes = $model->getAttributes();
        } elseif ($action === 'deleted') {
            $original = $model->getAttributes();
        }

        ActionLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'loggable_type' => get_class($model),
            'loggable_id' => $model->id,
            'original_data' => $original,
            'changed_data' => $changes,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Manually record a custom action for this model.
     * Useful for events like "Exported", "Printed", "Shared", etc.
     */
    public function recordCustomAction(string $action, ?array $details = null)
    {
        return ActionLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'loggable_type' => get_class($this),
            'loggable_id' => $this->id,
            'original_data' => null,
            'changed_data' => $details,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
