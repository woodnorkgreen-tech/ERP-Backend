<?php

namespace App\Modules\Finance\Payroll;

use App\Modules\HR\Models\Department;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Effective-dated labour classification (Report 67, MIG-H2).
 *
 * A department's pay is direct (cost of client work, posts to direct labour) or
 * indirect (office and administration, posts to salaries) from a date. Nothing
 * is inferred from a department's name or its people's roles: a department with
 * no classification in force is UNCLASSIFIED. Payroll still has to post it
 * somewhere, and posts it as overhead, the documented default until Finance
 * decides (Report 55, MIG-H2); the readiness projection says so by name.
 */
class LabourClassificationService
{
    public const DIRECT = Department::LABOUR_DIRECT;
    public const INDIRECT = Department::LABOUR_INDIRECT;
    public const UNCLASSIFIED = 'unclassified';

    /**
     * Record a classification from a date. Rows are only ever added; the
     * department's cached value follows once the date has arrived.
     */
    public function classify(Department $department, string $classification, string $effectiveFrom, ?int $actorId, ?string $reason): void
    {
        if (! in_array($classification, [self::DIRECT, self::INDIRECT], true)) {
            throw new InvalidArgumentException('A department is classified as direct or indirect labour.');
        }

        DB::transaction(function () use ($department, $classification, $effectiveFrom, $actorId, $reason) {
            DB::table('department_labour_classifications')->insert([
                'department_id' => $department->id, 'classification' => $classification, 'effective_from' => $effectiveFrom,
                'set_by' => $actorId, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $department->forceFill(['labour_classification' => $this->on($department->id, now())])->save();
        });
    }

    /** The classification in force for one department on a date, or null (unclassified). */
    public function on(int $departmentId, CarbonInterface $date): ?string
    {
        return $this->mapOn($date, collect([$departmentId]))[$departmentId] ?? null;
    }

    /**
     * department_id => direct|indirect in force on the date. A department with
     * no history row falls back to its stored value (rows created before this
     * table, and tests that set it directly); none at all means unclassified.
     *
     * @return array<int, string>
     */
    public function mapOn(CarbonInterface $date, ?Collection $departmentIds = null): array
    {
        $day = $date->toDateString();
        $history = DB::table('department_labour_classifications')
            ->when($departmentIds, fn ($q) => $q->whereIn('department_id', $departmentIds))
            ->whereDate('effective_from', '<=', $day)
            ->orderBy('effective_from')->orderBy('id')
            ->get(['department_id', 'classification'])
            ->groupBy('department_id')
            ->map(fn ($rows) => $rows->last()->classification);
        $withHistory = DB::table('department_labour_classifications')
            ->when($departmentIds, fn ($q) => $q->whereIn('department_id', $departmentIds))
            ->distinct()->pluck('department_id')->flip();
        $stored = DB::table('departments')->when($departmentIds, fn ($q) => $q->whereIn('id', $departmentIds))
            ->whereNotNull('labour_classification')->pluck('labour_classification', 'id');

        $map = [];
        foreach ($stored as $id => $value) {
            if (! $withHistory->has($id)) {
                $map[(int) $id] = $value;
            }
        }
        foreach ($history as $id => $value) {
            $map[(int) $id] = $value;
        }

        return $map;
    }
}
