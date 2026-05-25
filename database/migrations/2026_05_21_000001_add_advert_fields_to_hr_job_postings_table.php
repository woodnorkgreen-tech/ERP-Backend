<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('hr_job_postings', 'position_summary')) {
            Schema::table('hr_job_postings', function (Blueprint $table) {
                $table->longText('position_summary')->nullable()->after('location');
            });
        }

        if (! Schema::hasColumn('hr_job_postings', 'responsibilities')) {
            Schema::table('hr_job_postings', function (Blueprint $table) {
                $table->longText('responsibilities')->nullable()->after('description');
            });
        }

        if (! Schema::hasColumn('hr_job_postings', 'education_training')) {
            Schema::table('hr_job_postings', function (Blueprint $table) {
                $table->longText('education_training')->nullable()->after('responsibilities');
            });
        }

        if (! Schema::hasColumn('hr_job_postings', 'experience')) {
            Schema::table('hr_job_postings', function (Blueprint $table) {
                $table->longText('experience')->nullable()->after('education_training');
            });
        }

        if (! Schema::hasColumn('hr_job_postings', 'software_tools')) {
            Schema::table('hr_job_postings', function (Blueprint $table) {
                $table->longText('software_tools')->nullable()->after('experience');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('hr_job_postings', 'software_tools')) {
            Schema::table('hr_job_postings', function (Blueprint $table) {
                $table->dropColumn('software_tools');
            });
        }

        if (Schema::hasColumn('hr_job_postings', 'experience')) {
            Schema::table('hr_job_postings', function (Blueprint $table) {
                $table->dropColumn('experience');
            });
        }

        if (Schema::hasColumn('hr_job_postings', 'education_training')) {
            Schema::table('hr_job_postings', function (Blueprint $table) {
                $table->dropColumn('education_training');
            });
        }

        if (Schema::hasColumn('hr_job_postings', 'responsibilities')) {
            Schema::table('hr_job_postings', function (Blueprint $table) {
                $table->dropColumn('responsibilities');
            });
        }

        if (Schema::hasColumn('hr_job_postings', 'position_summary')) {
            Schema::table('hr_job_postings', function (Blueprint $table) {
                $table->dropColumn('position_summary');
            });
        }
    }
};
