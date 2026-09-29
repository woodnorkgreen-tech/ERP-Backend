<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * W6-5 / W6-6: Project financial closure tracking.
     *
     * Financial closure is a Finance control, separate from the project's
     * operational status. A project can be operationally "completed" or "closed"
     * while Finance is still settling costs — and the Finance closure can also
     * precede the operational closure.
     *
     * W6-6: The reopen columns record late-cost exceptions. The
     * finance.costs.reopen permission is created but assigned to no role —
     * WNG must explicitly grant it per event.
     */
    public function up(): void
    {
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->string('financial_closure_status', 32)
                ->default('open')
                ->after('mobilization_threshold_percentage')
                ->index();
            $table->unsignedBigInteger('financially_closed_by')->nullable()->after('financial_closure_status');
            $table->timestamp('financially_closed_at')->nullable()->after('financially_closed_by');
            // W6-6: when a closed project is reopened, record who approved it and why.
            $table->unsignedBigInteger('closure_reopened_by')->nullable()->after('financially_closed_at');
            $table->timestamp('closure_reopened_at')->nullable()->after('closure_reopened_by');
            $table->text('closure_reopen_reason')->nullable()->after('closure_reopened_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->dropColumn([
                'financial_closure_status',
                'financially_closed_by',
                'financially_closed_at',
                'closure_reopened_by',
                'closure_reopened_at',
                'closure_reopen_reason',
            ]);
        });
    }
};
