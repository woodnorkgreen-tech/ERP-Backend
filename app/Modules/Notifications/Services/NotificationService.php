<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Jobs\SendMailNotificationJob;
use App\Modules\Notifications\Jobs\SendMailToAddressNotificationJob;
use App\Modules\Notifications\Jobs\SendPushNotificationJob;
use App\Modules\Notifications\Models\AppNotification;
use App\Modules\Notifications\Models\AppNotificationPreference;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

class NotificationService
{
    public static function send(
        string $type,
        string $title,
        string $message,
        string $module,
        string $urgency = 'info',
        array $data = [],
        array $users = [],
        string|array $role = [],
        string|array $permission = [],
        string $notifyModule = '',
        bool $all = false,
        array $emails = [],
    ): Collection {
        return app(self::class)->dispatchNotification(
            $type,
            $title,
            $message,
            $module,
            $urgency,
            $data,
            $users,
            $role,
            $permission,
            $notifyModule,
            $all,
            $emails,
        );
    }

    public function dispatchNotification(
        string $type,
        string $title,
        string $message,
        string $module,
        string $urgency = 'info',
        array $data = [],
        array $users = [],
        string|array $role = [],
        string|array $permission = [],
        string $notifyModule = '',
        bool $all = false,
        array $emails = [],
    ): Collection {
        $registeredType = $this->registeredType($type);
        $module = $registeredType['module'] ?? $module;
        $urgency = $registeredType['urgency'] ?? $urgency;
        $defaultChannels = $registeredType['default_channels'] ?? ['database'];

        // Explicitly named recipients (e.g. "your own leave request was approved") are always
        // notified about their own record — module-visibility only applies to broadcast targeting
        // (role/permission/notifyModule/all), where it protects against over-notifying.
        $explicitRecipients = $this->resolveExplicitUsers($users);

        $broadcastRecipients = $this->resolveBroadcastRecipients($role, $permission, $notifyModule, $all)
            ->filter(fn (User $user) => $this->userCanSeeModule($user, $module));

        $recipients = $explicitRecipients->merge($broadcastRecipients)->unique('id')->values();

        $notifications = $recipients->map(function (User $user) use ($type, $title, $message, $module, $urgency, $data, $defaultChannels) {
            $enabledChannels = $this->enabledChannelsFor($user, $type, $defaultChannels);
            $notification = null;

            if ($enabledChannels->contains('database')) {
                $notification = AppNotification::create([
                    'user_id' => $user->id,
                    'module' => $module,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'urgency' => $urgency,
                ]);
            }

            $payload = [
                'notification_id' => $notification?->id,
                'user_id' => $user->id,
                'module' => $module,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'urgency' => $urgency,
            ];

            if ($enabledChannels->contains('mail')) {
                SendMailNotificationJob::dispatch($user->id, $payload);
            }

            if ($enabledChannels->contains('push')) {
                SendPushNotificationJob::dispatch($user->id, $payload);
            }

            return $notification;
        })->filter()->values();

        if (in_array('mail', $defaultChannels, true)) {
            collect($emails)
                ->filter(fn ($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
                ->map(fn (string $email) => strtolower(trim($email)))
                ->unique()
                ->each(function (string $email) use ($type, $title, $message, $module, $urgency, $data): void {
                    SendMailToAddressNotificationJob::dispatch($email, [
                        'notification_id' => null,
                        'user_id' => null,
                        'module' => $module,
                        'type' => $type,
                        'title' => $title,
                        'message' => $message,
                        'data' => $data,
                        'urgency' => $urgency,
                    ]);
                });
        }

        return $notifications;
    }

    protected function registeredType(string $type): array
    {
        $registered = config("notifications.types.$type");

        if (!$registered) {
            throw new InvalidArgumentException("Notification type [$type] is not registered.");
        }

        return $registered;
    }

    protected function resolveExplicitUsers(array $users): Collection
    {
        if ($users === []) {
            return collect();
        }

        $ids = collect($users)->map(fn ($user) => $user instanceof User ? $user->id : $user)->filter();

        return User::query()->whereIn('id', $ids)->get();
    }

    protected function resolveBroadcastRecipients(
        string|array $role,
        string|array $permission,
        string $notifyModule,
        bool $all,
    ): Collection {
        $resolved = collect();

        if ($all) {
            $resolved = $resolved->merge(User::query()->active()->get());
        }

        foreach ($this->asArray($role) as $roleName) {
            if (!SpatieRole::query()
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->exists()) {
                continue;
            }

            $resolved = $resolved->merge(User::query()->role($roleName)->active()->get());
        }

        foreach ($this->asArray($permission) as $permissionName) {
            if (!SpatiePermission::query()
                ->where('name', $permissionName)
                ->where('guard_name', 'web')
                ->exists()) {
                continue;
            }

            $resolved = $resolved->merge(User::query()->permission($permissionName)->active()->get());
        }

        if ($notifyModule !== '') {
            $resolved = $resolved->merge(
                User::query()->active()->get()->filter(fn (User $user) => $this->userCanSeeModule($user, $notifyModule))
            );
        }

        return $resolved->unique('id')->values();
    }

    protected function enabledChannelsFor(User $user, string $type, array $defaultChannels): Collection
    {
        $implementedChannels = collect(config('notifications.implemented_channels', ['database', 'mail', 'push']));
        $preferences = AppNotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->get()
            ->keyBy('channel');

        return collect(config('notifications.channels', ['database', 'mail', 'push']))
            ->filter(function (string $channel) use ($preferences, $defaultChannels) {
                if ($preferences->has($channel)) {
                    return (bool) $preferences[$channel]->enabled;
                }

                return in_array($channel, $defaultChannels, true);
            })
            ->intersect($implementedChannels)
            ->values();
    }

    public function userCanSeeModule(User $user, string $module): bool
    {
        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return true;
        }

        if (strtolower($module) === 'hr' && ($user->hasRole(['HR Admin', 'HR', 'Manager', 'Employee', 'Lead']) || $user->isDeptLead())) {
            return true;
        }

        $moduleRoles = [
            'finance' => ['Finance', 'Accounts'],
            'logistics' => ['Logistics', 'Driver', 'Manager'],
            'production' => ['Production', 'Production Manager', 'Quality Control', 'Manager'],
            'procurement-stores' => ['Procurement', 'Stores', 'Accounts', 'Manager'],
            // Client Service/Logistics added so logistics-task notifications
            // (e.g. manifest submissions awaiting review) reach the roles
            // that TASK_VISIBILITY_MAPPING now grants task access to.
            'projects' => ['Project Officer', 'Project Manager', 'Manager', 'Client Service', 'Logistics'],
            'design' => ['Designer'],
            'universal-task' => ['Employee', 'Manager'],
            'support' => ['Employee', 'Manager', 'HR', 'Finance', 'Accounts', 'Costing', 'Designer', 'Project Officer', 'Project Manager', 'Production', 'Logistics', 'Stores', 'Procurement'],
        ];

        if (isset($moduleRoles[strtolower($module)]) && $user->hasRole($moduleRoles[strtolower($module)])) {
            return true;
        }

        if (strtolower($module) === 'design') {
            $departmentName = $user->department?->name ?? $user->employee?->department?->name;
            if ($departmentName === 'Design/Creatives') {
                return true;
            }
        }

        return $user->can($module . '.access') || $user->can($module . '.read');
    }

    protected function asArray(string|array $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->filter(fn ($item) => $item !== null && $item !== '')
            ->values()
            ->all();
    }
}
