<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_device_sync_logs', function (Blueprint $table) {
            $table->dateTime('range_from')->nullable()->after('synced_at');
            $table->dateTime('range_to')->nullable()->after('range_from');
            $table->unsignedInteger('records_fetched')->default(0)->after('range_to');
            $table->unsignedInteger('records_duplicate')->default(0)->after('records_imported');
            $table->unsignedInteger('records_unmapped')->default(0)->after('records_processed');
            $table->unsignedInteger('records_failed')->default(0)->after('records_unmapped');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_device_sync_logs', function (Blueprint $table) {
            $table->dropColumn([
                'range_from',
                'range_to',
                'records_fetched',
                'records_duplicate',
                'records_unmapped',
                'records_failed',
            ]);
        });
    }
};
