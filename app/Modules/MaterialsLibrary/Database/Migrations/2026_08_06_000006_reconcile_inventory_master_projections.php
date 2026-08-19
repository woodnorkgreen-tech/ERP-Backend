<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // These columns are compatibility projections for older consumers. The
        // foreign keys and governed controls remain authoritative.
        DB::statement("UPDATE library_materials lm
            INNER JOIN units_of_measure u ON u.id = lm.base_uom_id
            SET lm.unit_of_measure = u.code
            WHERE lm.unit_of_measure <> u.code");

        DB::statement("UPDATE library_materials lm
            INNER JOIN material_categories c ON c.id = lm.material_category_id
            LEFT JOIN material_categories p ON p.id = c.parent_id
            SET lm.category = COALESCE(p.name, c.name),
                lm.subcategory = CASE WHEN p.id IS NULL THEN NULL ELSE c.name END");

        DB::statement("UPDATE stocks s
            INNER JOIN library_materials lm ON lm.id = s.material_id
            SET s.tracking_mode = CASE
                WHEN lm.tracking_mode = 'dimension_piece'
                 AND lm.issue_disposition = 'recoverable_remainder'
                    THEN 'individual'
                ELSE 'count'
            END");
    }

    public function down(): void
    {
        // No rollback: the previous duplicated strings/modes were inconsistent
        // and cannot be reconstructed reliably.
    }
};
