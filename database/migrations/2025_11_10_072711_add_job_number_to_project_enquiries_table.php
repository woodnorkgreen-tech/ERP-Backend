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
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->string('job_number')->nullable()->after('enquiry_number');
            $table->index('job_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->dropIndex(['job_number']);
            $table->dropColumn('job_number');
        });
    }
};
