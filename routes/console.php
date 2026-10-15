<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Project workflow coordination: escalate overdue task priorities AND send
// due-soon / overdue notifications. `tasks:check-escalations` is the single
// source of truth here; it superseded `tasks:notify-deadlines` (notify-only),
// which was removed 2026-07-21 as a confirmed-dead duplicate.
\Illuminate\Support\Facades\Schedule::command('tasks:check-escalations')
    ->dailyAt('07:00')
    ->withoutOverlapping();

// Kevin's request: reminders before loading/departure is due, and
// escalation to the Logistics team when it's overdue.
\Illuminate\Support\Facades\Schedule::command('logistics:loading-timeline-alerts')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Date-only delivery deadlines use the application's local timezone. Running
// every five minutes also catches up after downtime without requiring a page visit.
\Illuminate\Support\Facades\Schedule::command('projects:auto-complete-overdue')
    ->everyFiveMinutes()
    ->withoutOverlapping();
