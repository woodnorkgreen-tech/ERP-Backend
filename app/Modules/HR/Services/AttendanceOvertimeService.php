<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\AttendanceWorkSchedule;
use App\Modules\HR\Models\OTEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class AttendanceOvertimeService
{
    public function syncProposal(AttendanceRecord $record, AttendanceWorkSchedule $schedule): ?OTEntry
    {
        if (
            !Schema::hasTable('ot_entries')
            || !Schema::hasColumn('ot_entries', 'attendance_record_id')
            || !Schema::hasColumn('attendance_records', 'proposed_overtime_hours')
        ) {
            return null;
        }

        $hours = $this->calculateProposedHours($record, $schedule);
        $entry = OTEntry::withTrashed()
            ->where('attendance_record_id', $record->id)
            ->first();

        if ($entry && in_array($entry->status, ['approved', 'done'], true)) {
            $record->forceFill([
                'proposed_overtime_hours' => (float) $entry->hours,
                'approved_overtime_hours' => (float) $entry->hours,
                'overtime_status' => $entry->status,
                'overtime_hours' => (float) $entry->hours,
            ])->save();

            return $entry;
        }

        if ($hours <= 0 || !$record->clock_in || !$record->clock_out) {
            if ($entry) {
                $entry->delete();
            }
            $record->forceFill([
                'proposed_overtime_hours' => 0,
                'approved_overtime_hours' => 0,
                'overtime_status' => null,
                'overtime_hours' => 0,
            ])->save();

            return null;
        }

        [$startTime, $endTime] = $this->proposalTimes($record, $schedule);
        $proposalChanged = !$entry || round((float) $entry->hours, 2) !== round($hours, 2)
            || $entry->start_time !== $startTime
            || $entry->end_time !== $endTime;
        $status = $proposalChanged ? 'submitted' : ($entry->status ?? 'submitted');

        $entry = OTEntry::withTrashed()->updateOrCreate(
            ['attendance_record_id' => $record->id],
            [
                'employee_id' => $record->employee_id,
                'work_date' => $record->date->toDateString(),
                'start_time' => $startTime,
                'end_time' => $endTime,
                'hours' => $hours,
                'notes' => $record->is_holiday_work
                    ? "Attendance-derived off-day overtime: {$record->holiday_name}"
                    : 'Attendance-derived overtime after the standard threshold.',
                'status' => $status,
                'source_type' => 'attendance',
                'supervisor_approved_by' => $proposalChanged ? null : $entry?->supervisor_approved_by,
                'supervisor_approved_at' => $proposalChanged ? null : $entry?->supervisor_approved_at,
                'hr_approved_by' => $proposalChanged ? null : $entry?->hr_approved_by,
                'hr_approved_at' => $proposalChanged ? null : $entry?->hr_approved_at,
                'rejected_reason' => $proposalChanged ? null : $entry?->rejected_reason,
                'deleted_at' => null,
            ]
        );

        $record->forceFill([
            'proposed_overtime_hours' => $hours,
            'approved_overtime_hours' => 0,
            'overtime_status' => $status,
            'overtime_hours' => $hours,
        ])->save();

        return $entry;
    }

    public function markApproved(OTEntry $entry): void
    {
        if (!$entry->attendance_record_id || !Schema::hasColumn('attendance_records', 'approved_overtime_hours')) {
            return;
        }

        AttendanceRecord::withTrashed()
            ->whereKey($entry->attendance_record_id)
            ->update([
                'approved_overtime_hours' => $entry->hours,
                'overtime_status' => 'done',
            ]);
    }

    public function markStatus(OTEntry $entry, string $status): void
    {
        if (!$entry->attendance_record_id || !Schema::hasColumn('attendance_records', 'overtime_status')) {
            return;
        }

        AttendanceRecord::withTrashed()
            ->whereKey($entry->attendance_record_id)
            ->update([
                'overtime_status' => $status,
                'approved_overtime_hours' => $status === 'done' ? $entry->hours : 0,
            ]);
    }

    private function calculateProposedHours(AttendanceRecord $record, AttendanceWorkSchedule $schedule): float
    {
        if (!$record->clock_in || !$record->clock_out) {
            return 0.0;
        }

        if ($record->is_holiday_work) {
            return round((float) ($record->work_hours ?? 0), 2);
        }

        $clockOut = Carbon::createFromFormat('H:i:s', $this->normalizeTime($record->clock_out));
        $threshold = Carbon::createFromFormat(
            'H:i',
            config('hikvision.overtime_start', '18:00')
        );

        return $clockOut->gt($threshold)
            ? round($threshold->diffInMinutes($clockOut) / 60, 2)
            : 0.0;
    }

    private function proposalTimes(AttendanceRecord $record, AttendanceWorkSchedule $schedule): array
    {
        if ($record->is_holiday_work) {
            return [$this->normalizeTime($record->clock_in), $this->normalizeTime($record->clock_out)];
        }

        return [
            Carbon::createFromFormat('H:i', config('hikvision.overtime_start', '18:00'))->format('H:i:s'),
            $this->normalizeTime($record->clock_out),
        ];
    }

    private function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? $time . ':00' : $time;
    }
}
