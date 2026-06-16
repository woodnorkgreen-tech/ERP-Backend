<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Support\AttendancePersonId;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AttendanceEmployeeResolver
{
    public function map(array|Collection $personIds): Collection
    {
        $ids = collect($personIds)
            ->map(fn ($id) => AttendancePersonId::normalize($id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $fields = collect(['hikvision_id', 'id_number'])
            ->filter(fn ($field) => Schema::hasColumn('employees', $field))
            ->values();

        if ($fields->isEmpty()) {
            return collect();
        }

        $employees = Employee::query()
            ->select(['id', ...$fields->all()])
            ->where(function ($query) use ($fields, $ids) {
                foreach ($fields as $index => $field) {
                    $method = $index === 0 ? 'whereIn' : 'orWhereIn';
                    $query->{$method}($field, $ids->all());
                }
            })
            ->get();

        $map = collect();
        foreach ($employees as $employee) {
            foreach ($fields as $field) {
                $id = AttendancePersonId::normalize($employee->{$field});
                if ($id !== '' && $ids->contains($id) && !$map->has($id)) {
                    $map->put($id, $employee->id);
                }
            }
        }

        return $map;
    }
}
