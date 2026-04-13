<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_job_postings', function (Blueprint $table) {
            $table->json('shortlisting_criteria')->nullable()->after('requirements');
            $table->unsignedInteger('shortlist_threshold')->default(60)->after('shortlisting_criteria');
        });
    }

    public function down(): void
    {
        Schema::table('hr_job_postings', function (Blueprint $table) {
            $table->dropColumn(['shortlisting_criteria', 'shortlist_threshold']);
        });
    }
};
