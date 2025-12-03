<?php

namespace App\Modules\UniversalTask\Providers;

use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskAssignment;
use App\Modules\UniversalTask\Models\TaskIssue;
use App\Modules\UniversalTask\Observers\TaskObserver;
use App\Modules\UniversalTask\Observers\TaskAssignmentObserver;
use App\Modules\UniversalTask\Observers\TaskIssueObserver;
use App\Modules\UniversalTask\Events\TaskIssueLogged;
use App\Modules\UniversalTask\Events\TaskAssigned;
use App\Modules\UniversalTask\Events\TaskStatusChanged;
use App\Modules\UniversalTask\Events\TaskCompleted;
use App\Modules\UniversalTask\Events\TaskDueSoon;
use App\Modules\UniversalTask\Events\UserMentioned;
use App\Modules\UniversalTask\Listeners\SendTaskIssueNotification;
use App\Modules\UniversalTask\Listeners\SendTaskNotification;
use App\Modules\UniversalTask\Listeners\SendReminderNotification;
use App\Modules\UniversalTask\Listeners\SendEscalationNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class UniversalTaskServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge module configuration
        $this->mergeConfigFrom(
            __DIR__.'/../Config/universal-task.php',
            'universal-task'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Load migrations from module directory
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Load routes from module directory
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');

        // Publish configuration file
        $this->publishes([
            __DIR__.'/../Config/universal-task.php' => config_path('universal-task.php'),
        ], 'universal-task-config');

        // Register observers
        $this->registerObservers();

        // Register event listeners
        $this->registerEventListeners();
    }

    /**
     * Register model observers.
     */
    protected function registerObservers(): void
    {
        Task::observe(TaskObserver::class);
        TaskAssignment::observe(TaskAssignmentObserver::class);
        TaskIssue::observe(TaskIssueObserver::class);
    }

    /**
     * Register event listeners.
     */
    protected function registerEventListeners(): void
    {
        // Existing issue notification
        Event::listen(
            TaskIssueLogged::class,
            SendTaskIssueNotification::class
        );

        // Task notifications
        Event::listen(
            TaskAssigned::class,
            [SendTaskNotification::class, 'handleTaskAssigned']
        );

        Event::listen(
            TaskStatusChanged::class,
            [SendTaskNotification::class, 'handleTaskStatusChanged']
        );

        Event::listen(
            TaskCompleted::class,
            [SendTaskNotification::class, 'handleTaskCompleted']
        );

        Event::listen(
            UserMentioned::class,
            [SendTaskNotification::class, 'handleUserMentioned']
        );

        // Reminder notifications
        Event::listen(
            TaskDueSoon::class,
            SendReminderNotification::class
        );

        // Escalation notifications
        Event::listen(
            TaskStatusChanged::class,
            SendEscalationNotification::class
        );
    }
}
