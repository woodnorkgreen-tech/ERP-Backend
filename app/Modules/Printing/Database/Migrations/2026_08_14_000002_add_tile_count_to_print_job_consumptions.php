<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
<<<<<<< Updated upstream:app/Modules/Printing/Database/Migrations/2026_08_14_000002_add_tile_count_to_print_job_consumptions.php
        Schema::table('print_job_consumptions', function (Blueprint $table) {
            $table->unsignedInteger('tile_count')->default(1)->after('quantity');
        });
=======
        if (Schema::hasTable('assets') && !Schema::hasColumn('assets', 'next_service_date')) {
            Schema::table('assets', function (Blueprint $table) {
                $table->date('next_service_date')->nullable()->after('warranty_expiry');
            });
        }
>>>>>>> Stashed changes:app/Modules/Assets/Database/Migrations/2024_01_10_add_next_service_date_to_assets_table.php
    }

    public function down(): void
    {
<<<<<<< Updated upstream:app/Modules/Printing/Database/Migrations/2026_08_14_000002_add_tile_count_to_print_job_consumptions.php
        Schema::table('print_job_consumptions', function (Blueprint $table) {
            $table->dropColumn('tile_count');
        });
=======
        if (Schema::hasTable('assets') && Schema::hasColumn('assets', 'next_service_date')) {
            Schema::table('assets', function (Blueprint $table) {
                $table->dropColumn('next_service_date');
            });
        }
>>>>>>> Stashed changes:app/Modules/Assets/Database/Migrations/2024_01_10_add_next_service_date_to_assets_table.php
    }
};
