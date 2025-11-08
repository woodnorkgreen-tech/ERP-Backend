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
        Schema::table('enquiry_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('enquiry_tasks', 'department_id')) {
                $table->foreignId('department_id')->nullable()->constrained('departments')->onDelete('cascade');
                $table->index(['department_id']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enquiry_tasks', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
        });
    }
};
