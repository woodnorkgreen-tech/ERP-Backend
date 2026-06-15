<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Project workflow coordination: escalate overdue task priorities AND send
// due-soon / overdue notifications. `tasks:check-escalations` is the single
// source of truth here; it supersedes `tasks:notify-deadlines` (notify-only),
// which is left runnable on demand but unscheduled to avoid duplicate alerts.
\Illuminate\Support\Facades\Schedule::command('tasks:check-escalations')
    ->dailyAt('07:00')
    ->withoutOverlapping();
