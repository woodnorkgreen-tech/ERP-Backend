<?php

namespace App\Modules\HR\Console\Commands;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Notifications\ContractExpiryNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class NotifyExpiringContracts extends Command
{
    protected $signature = 'hr:notify-expiring-contracts {--days=30 : Look-ahead window in days}';

    protected $description = 'Email HR admins about employee contracts expiring within the look-ahead window';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $today = Carbon::today();
        $horizon = $today->copy()->addDays($days);

        $expiring = Employee::with('department')
            ->whereIn('status', ['active', 'on-leave'])
            ->whereNotNull('contract_end_date')
            ->whereBetween('contract_end_date', [$today->toDateString(), $horizon->toDateString()])
            ->orderBy('contract_end_date')
            ->get();

        if ($expiring->isEmpty()) {
            $this->info("No contracts expiring in the next {$days} days.");
            return Command::SUCCESS;
        }

        $this->info("Found {$expiring->count()} expiring contract(s). Notifying HR admins…");

        // Notify every user who holds an HR or Admin role
        $hrAdmins = \App\Models\User::role(['HR', 'Admin', 'Super Admin'])->get();

        if ($hrAdmins->isEmpty()) {
            $this->warn('No HR admin users found to notify.');
            return Command::SUCCESS;
        }

        Notification::send($hrAdmins, new ContractExpiryNotification($expiring->all()));

        $this->info("Notified {$hrAdmins->count()} HR admin(s).");
        return Command::SUCCESS;
    }
}
