<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('production_defect_codes')) {
            Schema::create('production_defect_codes', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->unique();
                $table->string('name', 150);
                $table->string('defect_group', 80)->nullable();
                $table->enum('default_severity', ['minor', 'major', 'critical'])->default('minor');
                $table->string('default_stage', 80)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['is_active', 'sort_order']);
            });
        }

        if (!Schema::hasTable('production_root_cause_codes')) {
            Schema::create('production_root_cause_codes', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->unique();
                $table->string('name', 150);
                $table->string('cause_group', 80)->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['is_active', 'sort_order']);
            });
        }

        $this->seedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('production_root_cause_codes');
        Schema::dropIfExists('production_defect_codes');
    }

    private function seedDefaults(): void
    {
        if (Schema::hasTable('production_defect_codes') && DB::table('production_defect_codes')->count() === 0) {
            DB::table('production_defect_codes')->insert([
                [
                    'code' => 'DIM_MISALIGN',
                    'name' => 'Dimensional Misalignment',
                    'defect_group' => 'dimensional',
                    'default_severity' => 'major',
                    'default_stage' => 'qc',
                    'is_active' => true,
                    'sort_order' => 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'code' => 'SURFACE_FINISH',
                    'name' => 'Surface Finish Defect',
                    'defect_group' => 'cosmetic',
                    'default_severity' => 'minor',
                    'default_stage' => 'qc',
                    'is_active' => true,
                    'sort_order' => 20,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'code' => 'WELD_INTEGRITY',
                    'name' => 'Weld Integrity Failure',
                    'defect_group' => 'structural',
                    'default_severity' => 'critical',
                    'default_stage' => 'qc',
                    'is_active' => true,
                    'sort_order' => 30,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'code' => 'PRINT_REGISTRATION',
                    'name' => 'Print Registration/Alignment Error',
                    'defect_group' => 'branding',
                    'default_severity' => 'major',
                    'default_stage' => 'qc',
                    'is_active' => true,
                    'sort_order' => 40,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'code' => 'ELECTRICAL_SAFETY',
                    'name' => 'Electrical Safety Non-Conformance',
                    'defect_group' => 'safety',
                    'default_severity' => 'critical',
                    'default_stage' => 'installation',
                    'is_active' => true,
                    'sort_order' => 50,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }

        if (Schema::hasTable('production_root_cause_codes') && DB::table('production_root_cause_codes')->count() === 0) {
            DB::table('production_root_cause_codes')->insert([
                [
                    'code' => 'MAT_SPEC_MISMATCH',
                    'name' => 'Material Specification Mismatch',
                    'cause_group' => 'material',
                    'description' => 'Wrong grade, gauge, or board type used.',
                    'is_active' => true,
                    'sort_order' => 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'code' => 'PROCESS_NON_ADHERENCE',
                    'name' => 'Process Not Followed',
                    'cause_group' => 'method',
                    'description' => 'Required SOP step skipped or incompletely executed.',
                    'is_active' => true,
                    'sort_order' => 20,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'code' => 'SKILL_GAP',
                    'name' => 'Operator Skill Gap',
                    'cause_group' => 'people',
                    'description' => 'Insufficient competency for task requirement.',
                    'is_active' => true,
                    'sort_order' => 30,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'code' => 'MEASURE_TOOL_ERROR',
                    'name' => 'Measurement/Tooling Error',
                    'cause_group' => 'equipment',
                    'description' => 'Incorrect calibration or wrong tooling setup.',
                    'is_active' => true,
                    'sort_order' => 40,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'code' => 'RUSH_TIMELINE',
                    'name' => 'Timeline Pressure / Rush Execution',
                    'cause_group' => 'planning',
                    'description' => 'Compressed schedule reduced control quality.',
                    'is_active' => true,
                    'sort_order' => 50,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }
};
