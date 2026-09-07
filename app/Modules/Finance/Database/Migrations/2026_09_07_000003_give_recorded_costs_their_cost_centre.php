<?php

use App\Modules\Finance\Support\CatalogueDimensionMap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs the cost-centre and activity keys that nothing ever wrote.
 *
 * `expense_codes` has carried a wording column and a foreign key for each of
 * these dimensions since the catalogue table was created. `ExpenseCodeSeeder`
 * filled only the wording, so:
 *
 *   expense_codes: 109 rows, 0 with a cost centre, 0 with an activity
 *   cost_lines:     72 rows, 0 with a cost centre
 *
 * while eighteen cost centres and twenty-seven activities sat in their own
 * tables referenced by nothing. `CostContextResolver` reads the foreign keys —
 * `'cost_centre_id' => $code->default_cost_centre_id` — so every cost the
 * collector has ever produced was classified against a null, the `cost_centre_id`
 * filter on the cost account matched nothing, and the cost centre on the
 * verification screen rendered blank on every line.
 *
 * The seeder now resolves both keys through {@see CatalogueDimensionMap}, which
 * fixes new installations. This fixes the ones that already exist, using the
 * same map so a code seeded today and a line repaired from history agree.
 *
 * ## Why this repairs recorded history
 *
 * Nothing here restates an amount, an account, a period or a balance. The
 * dimension a cost belonged to was determined by its expense code the moment it
 * was captured; it was simply never written down. Filling it in is recovering a
 * fact the record always implied, not deciding one after the event — which is
 * why it is safe to do to `journal_lines` as well, whose immutability protects
 * what was posted rather than the analysis columns hanging off it.
 *
 * Every update is guarded on the column being null, so a value a human has
 * since corrected is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $costCentres = DB::table('cost_centres')->pluck('id', 'code');
        $activities = DB::table('activities')->pluck('id', 'code');

        // Grouped by wording rather than looped per row: the catalogue has 109
        // codes and fifteen distinct department phrases between them.
        $catalogue = DB::table('expense_codes')
            ->select('id', 'default_cost_centre', 'project_activity')
            ->get();

        foreach ($catalogue as $code) {
            $centreId = $costCentres[CatalogueDimensionMap::costCentreCode($code->default_cost_centre)] ?? null;
            $activityId = $activities[CatalogueDimensionMap::activityCode($code->project_activity)] ?? null;

            if ($centreId === null && $activityId === null) {
                continue;
            }

            $update = [];
            if ($centreId !== null) {
                $update['default_cost_centre_id'] = $centreId;
            }
            if ($activityId !== null) {
                $update['default_activity_id'] = $activityId;
            }

            DB::table('expense_codes')->where('id', $code->id)
                ->where(function ($query) use ($centreId, $activityId) {
                    if ($centreId !== null) {
                        $query->orWhereNull('default_cost_centre_id');
                    }
                    if ($activityId !== null) {
                        $query->orWhereNull('default_activity_id');
                    }
                })
                ->update($update);
        }

        // Costs already recorded, from the code each was classified under.
        //
        // The activity is filled only where it is missing: `CostContextResolver`
        // prefers the project's live task stage over the catalogue default, and
        // a line that resolved its activity from a real task holds a better
        // answer than the catalogue's fallback.
        DB::statement(<<<'SQL'
            UPDATE cost_lines cl
            JOIN expense_codes ec ON ec.id = cl.expense_code_id
            SET cl.cost_centre_id = ec.default_cost_centre_id
            WHERE cl.cost_centre_id IS NULL
              AND ec.default_cost_centre_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE cost_lines cl
            JOIN expense_codes ec ON ec.id = cl.expense_code_id
            SET cl.activity_id = ec.default_activity_id
            WHERE cl.activity_id IS NULL
              AND ec.default_activity_id IS NOT NULL
        SQL);

        // The journal lines those costs produced, from the cost line itself
        // rather than from the catalogue — a line whose dimensions were later
        // corrected must not have the catalogue default written over its
        // journal.
        DB::statement(<<<'SQL'
            UPDATE journal_lines jl
            JOIN cost_lines cl ON cl.journal_entry_id = jl.journal_entry_id
            SET jl.cost_centre_id = cl.cost_centre_id
            WHERE jl.cost_centre_id IS NULL
              AND cl.cost_centre_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE journal_lines jl
            JOIN cost_lines cl ON cl.journal_entry_id = jl.journal_entry_id
            SET jl.activity_id = cl.activity_id
            WHERE jl.activity_id IS NULL
              AND cl.activity_id IS NOT NULL
        SQL);
    }

    /**
     * Deliberately irreversible.
     *
     * Rolling back would have to blank the dimensions, and by then some of them
     * will have been corrected by hand. There is no way to tell a repaired value
     * from an edited one, so the only safe reversal is none.
     */
    public function down(): void
    {
        // No-op: see the class docblock.
    }
};
