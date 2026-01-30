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
        Schema::table('material_versions', function (Blueprint $table) {
            $table->boolean('is_base')->default(false)->after('version_number');
            $table->text('reason')->nullable()->after('label');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('material_versions', function (Blueprint $table) {
            $table->dropColumn(['is_base', 'reason']);
        });
    }
};
