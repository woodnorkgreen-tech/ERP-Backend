<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_disbursements', function (Blueprint $table) {
            if (!Schema::hasColumn('petty_cash_disbursements', 'project_id')) {
                $table->foreignId('project_id')
                    ->nullable()
                    ->after('project_name')
                    ->constrained('projects')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('petty_cash_disbursements', 'project_enquiry_id')) {
                $table->foreignId('project_enquiry_id')
                    ->nullable()
                    ->after('project_id')
                    ->constrained('project_enquiries')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('petty_cash_disbursements', function (Blueprint $table) {
            if (Schema::hasColumn('petty_cash_disbursements', 'project_enquiry_id')) {
                $table->dropConstrainedForeignId('project_enquiry_id');
            }

            if (Schema::hasColumn('petty_cash_disbursements', 'project_id')) {
                $table->dropConstrainedForeignId('project_id');
            }
        });
    }
};
