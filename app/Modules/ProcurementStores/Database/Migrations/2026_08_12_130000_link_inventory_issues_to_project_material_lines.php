<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->foreignId('project_material_id')->nullable()->after('project_id')
                ->constrained('element_materials')->nullOnDelete();
        });

    }

    public function down(): void
    {
        Schema::table('inventory_logs', fn (Blueprint $table) => $table->dropConstrainedForeignId('project_material_id'));
    }
};
