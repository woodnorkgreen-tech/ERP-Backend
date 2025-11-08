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
        Schema::table('projects', function (Blueprint $table) {
            // Drop the existing foreign key
            $table->dropForeign(['enquiry_id']);
            // Change the foreign key to reference project_enquiries
            $table->foreign('enquiry_id')->references('id')->on('project_enquiries')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Drop the foreign key to project_enquiries
            $table->dropForeign(['enquiry_id']);
            // Recreate the foreign key to enquiries
            $table->foreign('enquiry_id')->references('id')->on('enquiries')->onDelete('cascade');
        });
    }
};
