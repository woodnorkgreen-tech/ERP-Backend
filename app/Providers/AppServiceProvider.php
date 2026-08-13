<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Modules\Production\Observers\ProjectEnquiryObserver;
use App\Modules\Production\Observers\ProjectObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Schema::defaultStringLength(191);

        // Super Admin Bypass: Grant unrestricted access to Super Administrators
        \Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
            return $user->hasRole('Super Admin') ? true : null;
        });

        /**
         * May this user sign off their own submission?
         *
         * Separation of duties is enforced in a dozen places — petty-cash
         * requisitions, spend vouchers, budget additions, cost verification,
         * client receipts, overtime. Each used to answer this question its own
         * way: some hard-coded `hasRole('Super Admin')`, most simply refused
         * with no exception at all, which meant a Super Admin could not clear a
         * blocked approval without a code change.
         *
         * They now all ask this one ability, so the answer is defined once.
         * Super Admin passes via the Gate::before bypass above without holding
         * the permission; anyone else needs APPROVALS_SELF_APPROVE, granted
         * through a role like any other permission.
         *
         * Callers remain responsible for recording that a self-approval took
         * place. This lifts the block; it does not hide the act.
         */
        \Illuminate\Support\Facades\Gate::define('self-approve', function ($user) {
            return $user->can(\App\Constants\Permissions::APPROVALS_SELF_APPROVE);
        });

        // Disable foreign key checks during migrations in local environment
        if (app()->environment('local') && app()->runningInConsole()) {
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            } catch (\Exception $e) {
                // Silently fail if DB is not reachable during boot
            }
        }

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        // Register model observers
        ProjectEnquiry::observe(ProjectEnquiryObserver::class);
        Project::observe(ProjectObserver::class);

        // Authorization policies (HR). Centralises authz that was previously inline role
        // checks scattered across the controllers.
        \Illuminate\Support\Facades\Gate::policy(
            \App\Modules\HR\Models\OTEntry::class,
            \App\Modules\HR\Policies\OvertimePolicy::class,
        );

        // Employee management policy — centralises view / create / update / delete /
        // viewSalary / viewPii / uploadPhoto authz in one place.
        \Illuminate\Support\Facades\Gate::policy(
            \App\Modules\HR\Models\Employee::class,
            \App\Modules\HR\Policies\EmployeePolicy::class,
        );

        \Illuminate\Support\Facades\Gate::policy(
            \App\Modules\Support\Models\SupportTicket::class,
            \App\Modules\Support\Policies\SupportTicketPolicy::class,
        );

        // Route model binding
        Route::bind('enquiry', function ($value) {
            return \App\Models\ProjectEnquiry::findOrFail($value);
        });
    }
}
