<?php

namespace App\Modules\HR\Console\Commands;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveBalanceAdjustment;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Services\LeaveManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportPastLeave extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hr:import-past-leave
        {csv : Path to the CSV file (see past_leave_import_draft.csv for the expected columns)}
        {--dry-run : Run all validation and print what would happen, without writing anything}
        {--actor= : User ID to record as the creator/approver of imported records (defaults to the first Super Admin user)}
        {--year=2026 : Year to record ADJUSTMENT rows against}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bulk-import historical leave (taken before/outside the system) from a reviewed CSV, as approved leave requests and/or balance adjustments';

    private const REQUIRED_COLUMNS = [
        'staff_name_sheet', 'matched_emp_code', 'row_type', 'leave_type',
        'start_date', 'end_date', 'days', 'reason',
    ];

    public function handle(LeaveManagementService $leaveService): int
    {
        $path = $this->argument('csv');
        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return Command::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $year = (int) $this->option('year');

        $actor = $this->option('actor')
            ? User::find($this->option('actor'))
            : User::where('email', 'admin@company.com')->first();

        if (!$actor) {
            $this->error('Could not resolve an actor user. Pass --actor=<user_id>.');
            return Command::FAILURE;
        }

        $rows = $this->readCsv($path);
        if ($rows === null) {
            return Command::FAILURE;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Importing as ' . $actor->name . " (#{$actor->id})");
        $this->newLine();

        $imported = 0;
        $skipped = 0;
        $failed = 0;

        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            foreach ($rows as $i => $row) {
                $line = $i + 2; // +1 for 0-index, +1 for header row
                $label = trim($row['staff_name_sheet']) . " (line {$line})";

                $rowType = strtoupper(trim($row['row_type']));

                if ($rowType === 'SKIP' || $rowType === '') {
                    $this->line("SKIP    {$label} -- marked skip in CSV");
                    $skipped++;
                    continue;
                }

                $empCode = trim($row['matched_emp_code']);
                if ($empCode === '') {
                    $this->warn("SKIP    {$label} -- no matched_emp_code, needs review");
                    $skipped++;
                    continue;
                }

                $employee = Employee::where('employee_id', $empCode)->first();
                if (!$employee) {
                    $this->warn("SKIP    {$label} -- employee_id '{$empCode}' not found");
                    $skipped++;
                    continue;
                }

                $leaveTypeCode = strtoupper(trim($row['leave_type']));
                $leaveType = LeaveType::where('code', $leaveTypeCode)->first();
                if (!$leaveType) {
                    $this->warn("SKIP    {$label} -- leave type '{$leaveTypeCode}' not found");
                    $skipped++;
                    continue;
                }

                $days = (float) $row['days'];
                $reason = trim($row['reason']) !== '' ? trim($row['reason']) : 'Historical leave import';

                try {
                    if ($rowType === 'ADJUSTMENT') {
                        if ($days <= 0) {
                            $this->line("SKIP    {$label} -- adjustment of 0 days, nothing to record");
                            $skipped++;
                            continue;
                        }

                        $duplicate = LeaveBalanceAdjustment::where('employee_id', $employee->id)
                            ->where('leave_type_id', $leaveType->id)
                            ->where('year', $year)
                            ->where('days', $days)
                            ->where('reason', $reason)
                            ->exists();

                        if ($duplicate) {
                            $this->line("SKIP    {$label} -- identical adjustment already exists");
                            $skipped++;
                            continue;
                        }

                        $leaveService->adjustBalance($employee, $leaveType, $days, $reason, $actor, $year);
                        $this->info("OK      {$label} -- adjustment: -{$days} {$leaveTypeCode} days ({$year})");
                        $imported++;
                    } elseif ($rowType === 'REQUEST') {
                        $startDate = trim($row['start_date']);
                        $endDate = trim($row['end_date']);

                        if ($startDate === '' || $endDate === '') {
                            $this->warn("SKIP    {$label} -- REQUEST row missing start/end date");
                            $skipped++;
                            continue;
                        }

                        $duplicate = LeaveRequest::where('employee_id', $employee->id)
                            ->where('leave_type_id', $leaveType->id)
                            ->where('start_date', $startDate)
                            ->where('end_date', $endDate)
                            ->exists();

                        if ($duplicate) {
                            $this->line("SKIP    {$label} -- identical leave request already exists");
                            $skipped++;
                            continue;
                        }

                        LeaveRequest::create([
                            'employee_id' => $employee->id,
                            'leave_type_id' => $leaveType->id,
                            'created_by' => $actor->id,
                            'approved_by' => $actor->id,
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                            'days_requested' => $days,
                            'session' => 'full_day',
                            'status' => LeaveRequest::STATUS_APPROVED,
                            'reason' => $reason,
                            'explanation' => 'Recorded from historical HR leave tracking sheet.',
                            'approved_at' => now(),
                        ]);

                        $this->info("OK      {$label} -- request: {$startDate} to {$endDate}, {$days} {$leaveTypeCode} days");
                        $imported++;
                    } else {
                        $this->warn("SKIP    {$label} -- unknown row_type '{$rowType}'");
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $this->error("FAIL    {$label} -- " . $e->getMessage());
                    $failed++;
                }
            }

            if ($dryRun) {
                $connection->rollBack();
                $this->newLine();
                $this->comment('DRY RUN -- nothing was written. Re-run without --dry-run once the CSV looks right.');
            } else {
                $connection->commit();
            }
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->error('Aborted, nothing was written: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->newLine();
        $this->table(['Imported', 'Skipped', 'Failed'], [[$imported, $skipped, $failed]]);

        return Command::SUCCESS;
    }

    private function readCsv(string $path): ?array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        if ($header === false) {
            $this->error('CSV appears to be empty.');
            fclose($handle);
            return null;
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, $header);
        if (!empty($missing)) {
            $this->error('CSV is missing required columns: ' . implode(', ', $missing));
            fclose($handle);
            return null;
        }

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) === 1 && trim($line[0]) === '') {
                continue; // blank line
            }
            $rows[] = array_combine($header, array_pad($line, count($header), ''));
        }

        fclose($handle);
        return $rows;
    }
}
