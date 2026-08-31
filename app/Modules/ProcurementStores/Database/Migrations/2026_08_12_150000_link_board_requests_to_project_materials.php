<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_requests', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('job_ref')->constrained('projects')->nullOnDelete();
            $table->foreignId('project_material_id')->nullable()->after('material_id')->constrained('element_materials')->nullOnDelete();
            $table->string('recipient_name')->nullable()->after('qty_requested');
        });
    }

    public function down(): void
    {
        Schema::table('board_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_material_id');
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn('recipient_name');
        });
    }
};
