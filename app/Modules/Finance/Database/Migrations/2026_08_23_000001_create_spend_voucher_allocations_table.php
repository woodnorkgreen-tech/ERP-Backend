<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spend_voucher_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spend_voucher_id')->constrained('spend_vouchers')->cascadeOnDelete();
            $table->foreignId('cost_line_id')->constrained('cost_lines')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->unique(['spend_voucher_id', 'cost_line_id'], 'voucher_cost_line_unique');
            $table->index(['cost_line_id', 'spend_voucher_id'], 'cost_line_voucher_index');
        });

        // Preserve allocations created by the original full-settlement design.
        DB::table('cost_lines')->whereNotNull('voucher_id')->orderBy('id')->chunkById(500, function ($lines) {
            $now = now();
            $rows = collect($lines)->map(fn ($line) => [
                'spend_voucher_id' => $line->voucher_id,
                'cost_line_id' => $line->id,
                'amount' => bcsub(
                    bcadd((string) ($line->net_amount ?? 0), (string) ($line->tax_amount ?? 0), 2),
                    (string) ($line->wht_amount ?? 0),
                    2
                ),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            DB::table('spend_voucher_allocations')->insertOrIgnore($rows);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spend_voucher_allocations');
    }
};
