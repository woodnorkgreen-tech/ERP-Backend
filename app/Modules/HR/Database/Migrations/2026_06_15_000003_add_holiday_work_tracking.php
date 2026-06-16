<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_holidays', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('name');
            $table->string('source_reference')->nullable()->after('source');
            $table->timestamp('imported_at')->nullable()->after('source_reference');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->boolean('is_holiday_work')->default(false)->after('status')->index();
            $table->string('holiday_name')->nullable()->after('is_holiday_work');
        });

        DB::table('attendance_work_schedules')
            ->where('is_default', true)
            ->update([
                'working_days' => json_encode([1, 2, 3, 4, 5, 6]),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex(['is_holiday_work']);
            $table->dropColumn(['is_holiday_work', 'holiday_name']);
        });
        Schema::table('attendance_holidays', function (Blueprint $table) {
            $table->dropColumn(['source', 'source_reference', 'imported_at']);
        });
        DB::table('attendance_work_schedules')
            ->where('is_default', true)
            ->update(['working_days' => json_encode([1, 2, 3, 4, 5])]);
    }
};
