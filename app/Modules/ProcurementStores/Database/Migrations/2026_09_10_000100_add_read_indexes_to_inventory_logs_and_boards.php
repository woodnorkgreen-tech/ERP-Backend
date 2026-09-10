<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read indexes for the Stores movement history and the board aggregates.
 *
 * inventory_logs was created with only its two foreign keys indexed. logged_at
 * was added later with no index at all, yet every movement list orders by it —
 * so the Stores dashboard and the material desk sorted the whole table on each
 * load. The project material desk filters by project_id, and the project-aware
 * material picker runs a per-material subquery over (material_id, type,
 * logged_at); none of those had an index that covered the shape.
 *
 * boards carries a single-column status index whose cardinality is a handful of
 * values. The inventory summary aggregates boards by material with a status
 * CASE, so the useful order is material first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            if (! $this->hasIndex('inventory_logs', 'inventory_logs_logged_at_created_at_index')) {
                $table->index(['logged_at', 'created_at'], 'inventory_logs_logged_at_created_at_index');
            }

            if (! $this->hasIndex('inventory_logs', 'inventory_logs_project_id_logged_at_index')) {
                $table->index(['project_id', 'logged_at'], 'inventory_logs_project_id_logged_at_index');
            }

            if (! $this->hasIndex('inventory_logs', 'inventory_logs_material_id_type_logged_at_index')) {
                $table->index(['material_id', 'type', 'logged_at'], 'inventory_logs_material_id_type_logged_at_index');
            }
        });

        Schema::table('boards', function (Blueprint $table) {
            if (! $this->hasIndex('boards', 'boards_library_material_id_status_index')) {
                $table->index(['library_material_id', 'status'], 'boards_library_material_id_status_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropIndex('inventory_logs_logged_at_created_at_index');
            $table->dropIndex('inventory_logs_project_id_logged_at_index');
            $table->dropIndex('inventory_logs_material_id_type_logged_at_index');
        });

        Schema::table('boards', function (Blueprint $table) {
            $table->dropIndex('boards_library_material_id_status_index');
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn ($existing) => $existing['name'] === $index);
    }
};
