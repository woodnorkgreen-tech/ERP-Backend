<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hr_job_postings', 'application_deadline')) {
            Schema::table('hr_job_postings', function (Blueprint $table) {
                $table->date('application_deadline')->nullable()->after('skillset');
            });
        }

        if (! Schema::hasColumn('hr_job_postings', 'reposted_from_id')) {
            Schema::table('hr_job_postings', function (Blueprint $table) {
                $table->foreignId('reposted_from_id')
                    ->nullable()
                    ->after('application_deadline')
                    ->constrained('hr_job_postings')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hr_job_postings', 'reposted_from_id')) {
            Schema::table('hr_job_postings', function (Blueprint $table) {
                $table->dropConstrainedForeignId('reposted_from_id');
            });
        }

        if (Schema::hasColumn('hr_job_postings', 'application_deadline')) {
            Schema::table('hr_job_postings', function (Blueprint $table) {
                $table->dropColumn('application_deadline');
            });
        }
    }
};
