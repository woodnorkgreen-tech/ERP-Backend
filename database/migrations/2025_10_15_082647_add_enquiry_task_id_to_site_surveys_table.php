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
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->foreignId('enquiry_task_id')->nullable()->constrained('enquiry_tasks')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->dropForeign(['enquiry_task_id']);
            $table->dropColumn('enquiry_task_id');
        });
    }
};
