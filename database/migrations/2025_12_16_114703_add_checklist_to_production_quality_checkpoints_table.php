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
        Schema::table('production_quality_checkpoints', function (Blueprint $table) {
            $table->json('checklist')->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_quality_checkpoints', function (Blueprint $table) {
            $table->dropColumn('checklist');
        });
    }
};
