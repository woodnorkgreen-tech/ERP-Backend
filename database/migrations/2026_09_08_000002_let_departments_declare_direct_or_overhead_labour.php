<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2: payroll stops being entirely office overhead.
 *
 * Every shilling of gross pay posts to `7550 Salaries & Wages`, an operating
 * expense — so a technician who spent the month building a client's stand is
 * recorded exactly like an accounts clerk. Two things follow: the cost of
 * delivering work is understated, overheads are overstated, and gross margin
 * cannot be computed at all.
 *
 * ## Why a department flag, and not an allocation to jobs
 *
 * The accounting answer everyone reaches for is to charge each person's time to
 * the jobs they worked on. That needs hours per person per job, and **WNG does
 * not collect them**: `attendance_records` holds 0 rows and `task_time_entries`
 * — a table that exists for exactly this — holds 0 rows.
 *
 * Spreading payroll across jobs by a formula (pro-rata by material cost, say)
 * would produce job margins that look precise and are invented. This codebase
 * has already learned that lesson once, with materials priced at budget: the fix
 * there was to flag the estimate rather than let it pass as a measurement.
 *
 * So this migration buys the distinction that IS supportable from data WNG
 * already keeps — which department someone belongs to — and stops there. That
 * gives a real company-level gross margin. Job-level labour cost stays
 * impossible until somebody records time against jobs, and saying so is more
 * useful than a number nobody can defend.
 *
 * ## Everything defaults to overhead, on purpose
 *
 * Null means indirect, which is exactly what the system does today. Classifying
 * a department is therefore an improvement someone opts into, never a silent
 * change to figures that have already been reported. `FinanceReadinessController`
 * reports how many departments are still unclassified so the lever is visible
 * rather than merely available.
 *
 * WHICH departments are direct is a WNG accounting policy decision, not a
 * technical one, so nothing is guessed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('labour_classification', 16)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('labour_classification');
        });
    }
};
