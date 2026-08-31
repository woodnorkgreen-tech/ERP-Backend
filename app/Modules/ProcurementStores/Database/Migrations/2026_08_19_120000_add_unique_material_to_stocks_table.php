<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One material has one stock row. Every writer already assumes it —
     * firstOrCreate() keys on material_id alone and LibraryMaterial::stock()
     * is a hasOne — but only a plain foreign-key index enforced it, so two
     * concurrent first receipts could insert two rows and split the balance
     * silently, with readers picking one arbitrarily.
     *
     * warehouse_code is deliberately NOT part of the key: the schema keeps one
     * balance per material, so making the pair unique would legitimise
     * per-warehouse rows the rest of the module cannot read.
     */
    public function up(): void
    {
        $duplicates = DB::table('stocks')
            ->select('material_id', DB::raw('COUNT(*) as rows_found'))
            ->groupBy('material_id')
            ->having('rows_found', '>', 1)
            ->pluck('rows_found', 'material_id');

        if ($duplicates->isNotEmpty()) {
            // Folding these together means choosing which balance is real and
            // which movement history belongs to it. That is a stock decision,
            // not a schema decision, so this stops rather than guessing.
            $detail = $duplicates->map(fn ($count, $materialId) => "material {$materialId}: {$count} rows")->implode('; ');

            throw new RuntimeException(
                "stocks holds duplicate rows per material and cannot be made unique until they are reconciled — {$detail}. "
                .'Run `php artisan inventory:audit-master-data` and merge each material to a single row first.'
            );
        }

        Schema::table('stocks', function (Blueprint $table) {
            $table->unique('material_id', 'stocks_material_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropUnique('stocks_material_id_unique');
        });
    }
};
