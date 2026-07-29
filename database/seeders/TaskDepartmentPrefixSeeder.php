<?php

namespace Database\Seeders;

use App\Modules\HR\Models\Department;
use App\Modules\UniversalTask\Models\TaskDepartmentPrefix;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TaskDepartmentPrefixSeeder extends Seeder
{
    private const PREFIXES = [
        'Projects' => 'PRJ',
        'Accounts/Finance' => 'FIN',
        'Production' => 'PROD',
        'Design/Creatives' => 'DES',
        'Procurement' => 'PROC',
        'Costing' => 'COST',
        'Logistics' => 'LOG',
        'Stores' => 'STR',
        'Client Service' => 'CS',
        'Teams' => 'TEAM',
        'ICT' => 'ICT',
    ];

    public function run(): void
    {
        if (!Schema::hasTable('task_department_prefixes')) {
            $this->command?->warn('Task department prefixes table does not exist yet. Run migrations first.');
            return;
        }

        Department::query()
            ->orderBy('name')
            ->get()
            ->each(function (Department $department): void {
                TaskDepartmentPrefix::query()->firstOrCreate(
                    ['department_id' => $department->id],
                    [
                        'prefix' => $this->prefixFor($department),
                        'notes' => 'Seeded from department list.',
                        'is_active' => true,
                        'created_by' => null,
                    ]
                );
            });
    }

    private function prefixFor(Department $department): string
    {
        $preferred = self::PREFIXES[$department->name] ?? $this->initials($department->name);
        $prefix = $this->normalizePrefix($preferred);

        if (!$this->prefixExists($prefix, $department)) {
            return $prefix;
        }

        $base = Str::limit($prefix, 9, '');
        for ($index = 2; $index <= 99; $index++) {
            $candidate = "{$base}{$index}";
            if (!$this->prefixExists($candidate, $department)) {
                return $candidate;
            }
        }

        return $this->normalizePrefix("D{$department->id}");
    }

    private function initials(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = collect($words)
            ->map(fn (string $word): string => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : "D{$name}";
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'TASK';

        return Str::upper(Str::limit($prefix, 12, ''));
    }

    private function prefixExists(string $prefix, Department $department): bool
    {
        return TaskDepartmentPrefix::query()
            ->where('prefix', $prefix)
            ->where('department_id', '!=', $department->id)
            ->exists();
    }
}
