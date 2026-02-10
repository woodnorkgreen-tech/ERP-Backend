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
        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->after('department_id');
            $table->unsignedBigInteger('enquiry_id')->nullable()->after('project_id');
            
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('set null');
            $table->foreign('enquiry_id')->references('id')->on('project_enquiries')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['enquiry_id']);
            $table->dropColumn(['project_id', 'enquiry_id']);
        });
    }
};
