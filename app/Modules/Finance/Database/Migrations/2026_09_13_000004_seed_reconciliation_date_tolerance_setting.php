<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEY = 'reconciliation_date_tolerance_days';

    private const EFFECTIVE_FROM = '2020-01-01';

    public function up(): void
    {
        DB::table('finance_settings')->updateOrInsert(
            ['key' => self::KEY, 'effective_from' => self::EFFECTIVE_FROM],
            [
                'value' => json_encode(3),
                'label' => 'Reconciliation date tolerance (days)',
                'description' => 'Maximum number of calendar days before or after the ERP posting date allowed for automatic matching. Amount, account and reference remain exact.',
                'approved_by' => null,
                'approved_at' => null,
                'effective_to' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('finance_settings')
            ->where('key', self::KEY)
            ->where('effective_from', self::EFFECTIVE_FROM)
            ->whereNull('approved_by')
            ->delete();
    }
};
