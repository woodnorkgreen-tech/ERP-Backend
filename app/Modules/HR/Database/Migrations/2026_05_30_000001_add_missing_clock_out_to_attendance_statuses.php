<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $statuses = [
        'present',
        'absent',
        'late',
        'early_departure',
        'half_day',
        'missing_clock_out',
        'on_leave',
        'holiday',
    ];

    public function up(): void
    {
        $this->updateStatusConstraint($this->statuses);

        DB::table('attendance_records')
            ->where('status', 'half_day')
            ->whereNull('clock_out')
            ->update(['status' => 'missing_clock_out']);
    }

    public function down(): void
    {
        DB::table('attendance_records')
            ->where('status', 'missing_clock_out')
            ->update(['status' => 'half_day']);

        $this->updateStatusConstraint(array_values(array_diff($this->statuses, ['missing_clock_out'])));
    }

    private function updateStatusConstraint(array $statuses): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $quotedStatuses = implode(', ', array_map(fn ($status) => "'{$status}'", $statuses));

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE attendance_records MODIFY status ENUM({$quotedStatuses}) NOT NULL DEFAULT 'present'");
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE attendance_records DROP CONSTRAINT IF EXISTS attendance_records_status_check');
            DB::statement("ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_status_check CHECK (status IN ({$quotedStatuses}))");
        }
    }
};
