<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The filing deadline the tax schedules quote, as data rather than a constant.
 *
 * `TaxScheduleService` tells Finance when each period's VAT and WHT fall due.
 * That is an assertion about Kenyan law, and this system should not be the
 * place it is hardcoded — filing calendars move by Finance Act, and when this
 * one does, the fix must be an edit by Finance rather than a deployment.
 *
 * Seeded at 20, which is Kenya's current due day for both the VAT return and
 * WHT remittance. The description says plainly that it needs confirming, in the
 * same voice the capitalisation threshold and petty-cash cap already use — those
 * were seeded as drafts for WNG's accountant to sign off, and this is one too.
 */
return new class extends Migration
{
    private const KEY = 'tax_return_due_day';

    public function up(): void
    {
        if (DB::table('finance_settings')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('finance_settings')->insert([
            'key' => self::KEY,
            'value' => '20',
            'label' => 'VAT / WHT filing due day of following month',
            'description' => 'KRA currently places both the VAT return and WHT remittance on the 20th of the '
                . 'month following the period. Drives the due dates quoted on the tax schedules. '
                . 'Confirm with WNG\'s tax advisor before Finance relies on these dates.',
            'effective_from' => '2020-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('finance_settings')->where('key', self::KEY)->delete();
    }
};
