<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Material category → expense code
    |--------------------------------------------------------------------------
    |
    | Finance governs how issued stock is classified. A Stores issue resolves its
    | expense code in descending order of authority:
    |
    |   1. the material's own configured code, in library_materials.attributes
    |      (`expense_code` / `finance_expense_code`) — per-item override;
    |   2. this map, keyed on the material's category and then its parent
    |      category — the normal governed path;
    |   3. `default_material_expense_code` below — the last resort.
    |
    | Keys are material_categories.name, matched on the material's own category
    | first and its root category second, so a child category may override its
    | parent by being listed here explicitly.
    |
    | Anything landing on the default is tagged `unmapped_expense_code` on the
    | cost line, so a missing entry here shows up as a reportable gap instead of
    | quietly accumulating in whichever account the default happens to name.
    |
    */

    'material_category_expense_codes' => [
        // Wood and board stock
        'Boards' => 'DM-WD-001',
        'Timber & Wood' => 'DM-WD-005',
        'Veneer' => 'DM-WD-007',
        'Adhesives & Laminates' => 'DM-WD-009',
        'Hardware & Fasteners' => 'DM-WD-010',

        // Sheet materials that are not wood
        'Sheet Materials' => 'DM-PL-003',

        // Metal
        'Metals & Profiles' => 'DM-MT-001',
        'Cutting Tools' => 'DM-MT-007',

        // Print and signage
        'Printing Media' => 'DM-PR-001',
        'Inks & Coatings' => 'DM-PR-005',
        'Packaging & Dispatch' => 'DM-PR-008',

        // Electrical
        'Electrical & LED' => 'DM-EL-001',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default material expense code
    |--------------------------------------------------------------------------
    |
    | Used only when neither the material nor its category is mapped. It is
    | deliberately a real, specific code rather than a generic bucket, because a
    | generic bucket would make the mapping gap invisible. Cost lines that reach
    | it carry `unmapped_expense_code` in their details.
    |
    | Refusing to post instead would strand physical stock movements outside
    | project cost, which is worse: the movement happened either way.
    |
    */

    'default_material_expense_code' => 'DM-WD-001',

];
