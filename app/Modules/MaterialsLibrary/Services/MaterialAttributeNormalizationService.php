<?php

namespace App\Modules\MaterialsLibrary\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MaterialAttributeNormalizationService
{
    public function preview(MaterialCategory $category): array
    {
        $rows = $this->candidates($category);
        return [
            'materials_checked' => $rows->count(),
            'materials_ready' => $rows->where('changed', true)->where('conflicts', [])->count(),
            'materials_with_conflicts' => $rows->filter(fn ($row) => $row['conflicts'] !== [])->count(),
            'fields' => $rows->flatMap(fn ($row) => array_keys($row['after']))->unique()->sort()->values()->all(),
            'examples' => $rows->filter(fn ($row) => $row['changed'] || $row['conflicts'] !== [])->take(10)->values()->all(),
        ];
    }

    public function apply(MaterialCategory $category, ?int $userId): array
    {
        return DB::transaction(function () use ($category, $userId): array {
            $rows = $this->candidates($category);
            $ready = $rows->where('changed', true)->where('conflicts', []);
            $skipped = $rows->filter(fn ($row) => $row['conflicts'] !== [])->count();
            $runId = DB::table('material_attribute_normalization_runs')->insertGetId([
                'material_category_id' => $category->id, 'created_by' => $userId,
                'materials_changed' => $ready->count(), 'materials_skipped' => $skipped,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($ready as $row) {
                DB::table('material_attribute_normalization_items')->insert([
                    'run_id' => $runId, 'material_id' => $row['material_id'],
                    'before_attributes' => json_encode($row['before'], JSON_THROW_ON_ERROR),
                    'after_attributes' => json_encode($row['after'], JSON_THROW_ON_ERROR),
                ]);
                LibraryMaterial::findOrFail($row['material_id'])->update(['attributes' => $row['after']]);
            }
            return ['run_id' => $runId, 'materials_changed' => $ready->count(), 'materials_skipped' => $skipped];
        });
    }

    public function rollback(int $runId): array
    {
        return DB::transaction(function () use ($runId): array {
            $run = DB::table('material_attribute_normalization_runs')->lockForUpdate()->find($runId);
            abort_unless($run, 404, 'Normalization run not found.');
            abort_if($run->rolled_back_at, 422, 'This normalization has already been rolled back.');
            $items = DB::table('material_attribute_normalization_items')->where('run_id', $runId)->get();
            foreach ($items as $item) {
                LibraryMaterial::findOrFail($item->material_id)->update(['attributes' => json_decode($item->before_attributes, true)]);
            }
            DB::table('material_attribute_normalization_runs')->where('id', $runId)->update(['rolled_back_at' => now(), 'updated_at' => now()]);
            return ['run_id' => $runId, 'materials_restored' => $items->count()];
        });
    }

    private function candidates(MaterialCategory $category)
    {
        return LibraryMaterial::where('material_category_id', $category->id)->get(['id', 'material_name', 'attributes'])->map(function ($material): array {
            $before = is_array($material->attributes) ? $material->attributes : [];
            $source = is_array($before['attributes'] ?? null) ? array_merge(array_diff_key($before, ['attributes' => true]), $before['attributes']) : $before;
            $after = []; $conflicts = [];
            foreach ($source as $rawKey => $value) {
                $key = Str::of((string) $rawKey)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
                if ($key === '') continue;
                if (array_key_exists($key, $after) && $after[$key] !== $value) { $conflicts[] = ['key' => $key, 'values' => [$after[$key], $value]]; continue; }
                $after[$key] = $value;
            }
            ksort($after);
            return ['material_id' => $material->id, 'material_name' => $material->material_name, 'before' => $before, 'after' => $after, 'changed' => $before !== $after, 'conflicts' => $conflicts];
        });
    }
}
