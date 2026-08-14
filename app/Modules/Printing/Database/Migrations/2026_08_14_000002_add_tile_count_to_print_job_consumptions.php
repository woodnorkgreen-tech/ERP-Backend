<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_job_consumptions', function (Blueprint $table) {
            $table->unsignedInteger('tile_count')->default(1)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('print_job_consumptions', function (Blueprint $table) {
            $table->dropColumn('tile_count');
        });
    }
};
