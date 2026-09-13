<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FINANCE_ONLY_CODES = [
        'DL-CAS-001',
        'DL-CAS-002',
        'RW-LAB-001',
        'VS-PRM-001',
        'OE-TRP-001',
        'OE-COM-001',
        'OE-WEL-001',
        'OE-UTL-001',
        'OE-UTL-002',
        'NE-011',
        'NE-012',
    ];

    public function up(): void
    {
        DB::table('expense_codes')
            ->whereIn('code', self::FINANCE_ONLY_CODES)
            ->update(['is_procurable' => false]);
    }

    public function down(): void
    {
        DB::table('expense_codes')
            ->whereIn('code', self::FINANCE_ONLY_CODES)
            ->update(['is_procurable' => true]);
    }
};
