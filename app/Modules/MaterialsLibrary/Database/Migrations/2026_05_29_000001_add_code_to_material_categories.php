<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_categories', function (Blueprint $table) {
            $table->string('code', 6)
                ->nullable()
                ->after('name')
                ->comment('Short uppercase SKU prefix used in material code generation, e.g. MDF, PLY, VNL');

            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::table('material_categories', function (Blueprint $table) {
            $table->dropIndex(['code']);
            $table->dropColumn('code');
        });
    }
};
