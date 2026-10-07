<?php

use App\Constants\ScopeClassification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Preflight before DDL: no historical element may be silently discarded.
        $orphans = DB::table('project_elements as pe')
            ->leftJoin('task_materials_data as m', 'm.id', '=', 'pe.task_materials_data_id')
            ->leftJoin('enquiry_tasks as t', 't.id', '=', 'm.enquiry_task_id')
            ->leftJoin('project_enquiries as e', 'e.id', '=', 't.project_enquiry_id')
            ->whereNull('e.id')->count();
        $orphanMaterials = DB::table('element_materials as em')->leftJoin('project_elements as pe', 'pe.id', '=', 'em.project_element_id')->whereNull('pe.id')->count();
        $orphanBudgetSources = Schema::hasColumn('budget_additions', 'source_element_id')
            ? DB::table('budget_additions as b')->leftJoin('project_elements as pe', 'pe.id', '=', 'b.source_element_id')->whereNotNull('b.source_element_id')->whereNull('pe.id')->count() : 0;
        if ($orphanBudgetSources) {
            throw new RuntimeException('Repair orphan budget element references before consolidating.');
        }
        if ($orphans || $orphanMaterials) {
            throw new RuntimeException('Repair orphan project elements/materials before consolidating: '.$orphans.' elements, '.$orphanMaterials.' materials.');
        }

        Schema::table('project_enquiries', fn (Blueprint $table) => $table->unsignedInteger('elements_revision')->default(0));

        Schema::table('project_deliverables', function (Blueprint $table) {
            $table->string('name', 500)->change();
            $table->unsignedBigInteger('task_materials_data_id')->nullable()->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_project_element_id')->nullable()->unique();
            $table->uuid('persistent_id')->nullable()->unique();
            $table->string('template_id', 255)->nullable();
            $table->string('element_type', 500)->nullable();
            $table->string('category', 30)->default('production');
            $table->decimal('required_quantity', 14, 4)->default(1);
            $table->string('unit_of_measurement', 100)->default('Pcs');
            $table->json('dimensions')->nullable();
            $table->boolean('is_included')->default(true);
            $table->text('notes')->nullable();
            $table->json('source_metadata')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable()->index();
            $table->foreign('task_materials_data_id')->references('id')->on('task_materials_data')->nullOnDelete();
            $table->foreign('parent_id')->references('id')->on('project_deliverables')->nullOnDelete();
        });

        Schema::table('element_materials', fn (Blueprint $table) => $table->timestamp('archived_at')->nullable()->index());

        // The live database may lack the originally-declared foreign keys.
        // Discover actual constraints instead of assuming their names/existence.
        $references = [['element_materials', 'project_element_id', 'cascade'], ['budget_additions', 'source_element_id', 'set null']];
        foreach ($references as [$table, $column]) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table) as $foreign) {
                if (in_array($column, $foreign['columns'], true)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($foreign['name']));
                }
            }
        }

        DB::transaction(function () {
            foreach (DB::table('project_deliverables')->orderBy('id')->get() as $row) {
                DB::table('project_deliverables')->where('id', $row->id)->update([
                    'persistent_id' => (string) Str::uuid(),
                    'element_type' => $row->classification,
                    'category' => ScopeClassification::toElementCategory($row->classification),
                ]);
            }
            $rows = DB::table('project_elements as pe')
                ->join('task_materials_data as m', 'm.id', '=', 'pe.task_materials_data_id')
                ->join('enquiry_tasks as t', 't.id', '=', 'm.enquiry_task_id')
                ->select('pe.*', 't.project_enquiry_id')->orderBy('pe.id')->get();
            $mapping = [];
            foreach ($rows as $row) {
                $candidates = DB::table('project_deliverables')->where('enquiry_id', $row->project_enquiry_id)->whereNull('legacy_project_element_id');
                $parent = null;
                if (! empty($row->scope_id)) {
                    $parent = DB::table('project_deliverables')->where('enquiry_id', $row->project_enquiry_id)->where('uuid', $row->scope_id)->first();
                    $match = $parent && $parent->legacy_project_element_id === null ? $parent : null;
                } else {
                    // Name matching is only safe when BOTH sides are unique within this enquiry.
                    $normalized = mb_strtolower(trim($row->name ?? ''));
                    $sameName = DB::table('project_deliverables')->where('enquiry_id', $row->project_enquiry_id)->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])->get();
                    $materialMatches = $rows->filter(fn ($other) => $other->project_enquiry_id === $row->project_enquiry_id && mb_strtolower(trim($other->name ?? '')) === $normalized);
                    $match = $normalized !== '' && $sameName->count() === 1 && $materialMatches->count() === 1 && $sameName->first()->legacy_project_element_id === null ? $sameName->first() : null;
                }
                $fields = [
                    'task_materials_data_id' => $row->task_materials_data_id,
                    'legacy_project_element_id' => $row->id,
                    'persistent_id' => $row->persistent_id ?: (string) Str::uuid(),
                    'template_id' => $row->template_id,
                    'element_type' => $row->element_type,
                    'category' => $row->category,
                    'required_quantity' => $row->required_quantity ?? 1,
                    'unit_of_measurement' => $row->unit_of_measurement ?? 'Pcs',
                    'dimensions' => $row->dimensions,
                    'is_included' => $row->is_included,
                    'notes' => $row->notes,
                    'source_metadata' => $row->source_metadata ?? null,
                    'sort_order' => $row->sort_order,
                    'updated_at' => $row->updated_at,
                ];
                if ($match) {
                    $id = $match->id;
                    // The enquiry's name/classification and Design foreign key remain authoritative.
                    DB::table('project_deliverables')->where('id', $id)->update($fields);
                } else {
                    $id = DB::table('project_deliverables')->insertGetId($fields + [
                        'enquiry_id' => $row->project_enquiry_id,
                        'uuid' => (string) Str::uuid(),
                        'name' => $row->name ?: $row->element_type,
                        'classification' => 'PRE-DEFINED',
                        'status' => 'original',
                        'parent_id' => $parent?->id,
                        'created_at' => $row->created_at,
                    ]);
                }
                $mapping[$row->id] = $id;
            }
            // Remap from a captured original set so overlapping old/new IDs cannot remap twice.
            foreach (DB::table('element_materials')->select('id', 'project_element_id')->get() as $row) {
                DB::table('element_materials')->where('id', $row->id)->update(['project_element_id' => $mapping[$row->project_element_id]]);
            }
            if (Schema::hasColumn('budget_additions', 'source_element_id')) {
                foreach (DB::table('budget_additions')->whereNotNull('source_element_id')->select('id', 'source_element_id')->get() as $row) {
                    DB::table('budget_additions')->where('id', $row->id)->update(['source_element_id' => $mapping[$row->source_element_id]]);
                }
            }
        });
        foreach ($references as [$table, $column, $delete]) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column)->references('id')->on('project_deliverables')->onDelete($delete));
            }
        }
        // Preserve the original rows for audit/recovery; application models no longer write them.
        Schema::rename('project_elements', 'project_elements_legacy');
    }

    public function down(): void
    {
        throw new RuntimeException('This consolidation preserves a legacy archive but cannot safely reverse new shared-element writes. Restore a pre-migration database backup to roll back.');
    }
};
