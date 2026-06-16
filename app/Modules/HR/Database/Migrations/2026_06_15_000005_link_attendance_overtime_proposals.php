<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ot_entries', function (Blueprint $table) {
            $table->foreignId('attendance_record_id')->nullable()->after('id')
                ->constrained('attendance_records')->nullOnDelete();
            $table->string('source_type', 32)->default('manual')->after('attendance_record_id');
            $table->unique('attendance_record_id');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->decimal('proposed_overtime_hours', 8, 2)->default(0)->after('overtime_hours');
            $table->decimal('approved_overtime_hours', 8, 2)->default(0)->after('proposed_overtime_hours');
            $table->string('overtime_status', 32)->nullable()->after('approved_overtime_hours')->index();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex(['overtime_status']);
            $table->dropColumn([
                'proposed_overtime_hours',
                'approved_overtime_hours',
                'overtime_status',
            ]);
        });

        Schema::table('ot_entries', function (Blueprint $table) {
            $table->dropUnique(['attendance_record_id']);
            $table->dropConstrainedForeignId('attendance_record_id');
            $table->dropColumn('source_type');
        });
    }
};
