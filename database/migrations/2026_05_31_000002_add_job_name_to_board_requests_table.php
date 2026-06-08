<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_requests', function (Blueprint $table) {
            // Human-readable project/enquiry name — shown in all workflow views
            $table->string('job_name')->nullable()->after('job_ref');
        });
    }

    public function down(): void
    {
        Schema::table('board_requests', function (Blueprint $table) {
            $table->dropColumn('job_name');
        });
    }
};
