<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hr_job_postings', 'skillset')) {
            Schema::table('hr_job_postings', function (Blueprint $table) {
                $table->longText('skillset')->nullable()->after('software_tools');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hr_job_postings', 'skillset')) {
            Schema::table('hr_job_postings', function (Blueprint $table) {
                $table->dropColumn('skillset');
            });
        }
    }
};
