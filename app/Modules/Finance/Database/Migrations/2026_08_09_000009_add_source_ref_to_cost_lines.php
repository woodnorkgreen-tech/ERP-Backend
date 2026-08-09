<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens the idempotency key to cover string-keyed sources.
 *
 * Budget lines are identified inside their JSON by strings, not integers —
 * materials use UUIDs, labour/expenses/logistics use `labour-1784964925457`
 * style keys — so `source_id` alone cannot identify them. A projected budget
 * line is therefore keyed as (BudgetLine, task_budget_data.id, "<json line id>").
 *
 * `source_ref` defaults to '' rather than NULL deliberately. MySQL treats NULLs
 * as distinct in a unique index, so a nullable column here would silently permit
 * exactly the duplicates this index exists to prevent. With '' as the default,
 * the tuple contains no NULLs whenever a source is set, and the constraint bites.
 *
 * Manual captures still carry NULL source_type/source_id, so they remain
 * correctly exempt — two people reporting two genuinely different cash purchases
 * with identical details must both be recordable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_lines', function (Blueprint $table) {
            $table->string('source_ref', 64)->default('')->after('source_id');
            $table->dropUnique('cost_lines_source_unique');
            $table->unique(['source_type', 'source_id', 'source_ref'], 'cost_lines_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cost_lines', function (Blueprint $table) {
            $table->dropUnique('cost_lines_source_unique');
            $table->dropColumn('source_ref');
            $table->unique(['source_type', 'source_id'], 'cost_lines_source_unique');
        });
    }
};
