<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('daily_issues', function (Blueprint $table) {
            // Drop the existing enum column and recreate it with all possible values
            $table->dropColumn('status');
            $table->enum('status', ['open', 'resolved', 'escalated', 'under_review'])->default('open');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('daily_issues', function (Blueprint $table) {
            // Revert back to original enum values
            $table->dropColumn('status');
            $table->enum('status', ['open', 'resolved'])->default('open');
        });
    }
};
