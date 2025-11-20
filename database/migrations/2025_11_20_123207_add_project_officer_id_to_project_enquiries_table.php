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
            $table->foreignId('project_officer_id')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null')
                  ->after('contact_person');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->dropForeign(['project_officer_id']);
            $table->dropColumn('project_officer_id');
        });
    }
};
