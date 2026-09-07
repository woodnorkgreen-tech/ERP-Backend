<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes the fund-requisition category list a subset of the expense catalogue.
 *
 * There were two vocabularies for "what was it for?": eight folk categories on
 * the requisition (Transport, Meals, Fuel…) and ninety-four governed expense
 * codes on the cost side. `default_expense_code_id` was built to bridge them
 * and was never set on a single type, so the accounting classification did not
 * exist until somebody picked a code at payout.
 *
 * It could not have been set, either. The folk categories straddle the line the
 * catalogue draws through `job_id_rule`: "Transport" is OE-TRP-001 when it is
 * office spend (job number not allowed) and TL-CRW-001 when it is crew moving
 * to a site (job number required). One default per type cannot say both.
 *
 * So the categories become the codes, named the way a requester would say them.
 * The requester's own words then carry the accounting decision, `requires_project`
 * follows the code's `job_id_rule` instead of being set by hand beside it, and a
 * commitment raised on approval arrives classified.
 *
 * Only codes that are genuinely spent out of the cash tin are listed. Equipment
 * hire, subcontractors, professional fees, insurance and venue hire are supplier
 * invoiced and belong on a purchase order; OE-FIN-001 is posted automatically
 * from the payment itself and is never requested.
 */
return new class extends Migration
{
    /**
     * code => [category name, recipient mode].
     *
     * `requires_project` is not listed: it is read from the expense code's
     * job_id_rule below, so the two can never disagree.
     */
    private const JOURNEY_FIELDS = [
        ['key' => 'travel_date', 'type' => 'date', 'required' => true],
        ['key' => 'journey_purpose', 'type' => 'text', 'required' => true],
    ];

    private const FUEL_FIELDS = [
        ['key' => 'vehicle_or_asset', 'type' => 'text', 'required' => true],
        ['key' => 'odometer_or_hours', 'type' => 'number'],
    ];

    private const MEAL_FIELDS = [
        ['key' => 'service_date', 'label' => 'Meal date', 'type' => 'date', 'required' => true],
        ['key' => 'meal_type', 'type' => 'select', 'required' => true,
            'options' => ['Breakfast', 'Lunch', 'Dinner', 'Refreshments']],
    ];

    private const AIRTIME_FIELDS = [
        ['key' => 'coverage_period', 'type' => 'text', 'placeholder' => 'e.g. August 2026'],
    ];

    /**
     * code => [category name, recipient mode, extra questions].
     *
     * `requires_project` is not listed: it is read from the expense code's
     * job_id_rule below, so the two can never disagree.
     *
     * The extra questions are the ones the superseded categories already asked —
     * a fuel request has always wanted the vehicle and the odometer reading, a
     * meal request the sitting. They are carried across rather than reinvented,
     * and no category is given questions nobody has asked for: the type builder
     * is there for an administrator to add them.
     */
    private const CATEGORIES = [
        // Moving people and goods
        'TL-CRW-001' => ['Crew transport', 'per_item', self::JOURNEY_FIELDS],
        'TL-FUE-001' => ['Fuel for project transport', 'single', self::FUEL_FIELDS],
        'TL-HIR-001' => ['Truck & vehicle hire', 'single', self::JOURNEY_FIELDS],
        'TL-CUR-001' => ['Courier & dispatch', 'single', []],
        'TL-HND-001' => ['Loading & handling', 'per_item', []],

        // Looking after the crew
        'PF-MEA-001' => ['Site meals & refreshments', 'single', self::MEAL_FIELDS],
        'PF-ACC-001' => ['Crew accommodation', 'per_item', []],
        'PF-PDM-001' => ['Out-of-town per diem', 'per_item', []],
        'DL-CAS-001' => ['Casual site labour', 'per_item', []],
        'DL-CAS-002' => ['Casual workshop labour', 'per_item', []],
        'DL-ALW-001' => ['Site allowance & overtime', 'per_item', []],

        // Running the site
        'PU-FUE-001' => ['Generator fuel', 'single', self::FUEL_FIELDS],
        'PU-PWR-001' => ['Site power', 'single', []],
        'PU-WTR-001' => ['Site water & sanitation', 'single', []],
        'PU-WST-001' => ['Site waste removal', 'single', []],
        'EQ-SAF-001' => ['Site safety & PPE', 'single', []],
        'EQ-TOL-001' => ['Site tools & consumables', 'single', []],
        'VS-PRM-001' => ['County permits & licences', 'single', []],
        'VS-SEC-001' => ['Site security', 'per_item', []],

        // Office, never charged to a project
        'OE-COM-001' => ['Airtime & internet', 'per_item', self::AIRTIME_FIELDS],
        'OE-TRP-001' => ['Office & admin transport', 'single', self::JOURNEY_FIELDS],
        'OE-WEL-001' => ['Staff welfare', 'single', []],
    ];

    /**
     * The folk categories being replaced.
     *
     * "Miscellaneous" is retired rather than mapped, deliberately: a catch-all
     * absorbs exactly the spend that most needs classifying, and there is no
     * code for it because there should not be one. A request that fits nothing
     * on the list is a missing code, which is a conversation, not a bucket.
     *
     * "Office Supplies" and "Repair & Maintenance" have no code either — the
     * catalogue has never carried one. They are retired here rather than
     * silently mapped somewhere plausible; naming their codes is a decision for
     * whoever owns the chart of accounts.
     *
     * All eight are retired, including those no requisition has ever used. The
     * screen deletes an unused type outright, which is right for one somebody
     * removes by hand — but each of these carries questions an administrator
     * wrote, and the two with no successor code are the ones most likely to
     * come back. Deactivating takes them out of the picker just as completely
     * and keeps that work.
     */
    private const SUPERSEDED = [
        'projects', 'office_supplies', 'transport', 'meals',
        'repair_maintenance', 'fuel_lubricants', 'communication_airtime', 'miscellaneous',
    ];

    public function up(): void
    {
        $codes = DB::table('expense_codes')
            ->whereIn('code', array_keys(self::CATEGORIES))
            ->pluck('job_id_rule', 'code');

        $sort = 0;
        foreach (self::CATEGORIES as $code => [$name, $recipientMode, $requestFields]) {
            // A code the catalogue does not carry is skipped rather than
            // guessed at: an unresolvable category would be worse than none.
            if (! $codes->has($code)) {
                continue;
            }

            $expenseCodeId = DB::table('expense_codes')->where('code', $code)->value('id');

            DB::table('petty_cash_requisition_types')->updateOrInsert(
                ['code' => strtolower(str_replace('-', '_', $code))],
                [
                    'name' => $name,
                    'recipient_mode' => $recipientMode,
                    'requires_project' => $codes[$code] === 'required' ? 1 : 0,
                    'default_expense_code_id' => $expenseCodeId,
                    'request_fields' => $requestFields ? json_encode($requestFields) : null,
                    'is_active' => 1,
                    'sort_order' => ++$sort,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        // A used category is retired, never deleted — existing requisitions
        // keep the type they were raised under. This is the rule the type
        // screen already applies when somebody removes one by hand.
        DB::table('petty_cash_requisition_types')
            ->whereIn('code', self::SUPERSEDED)
            ->update(['is_active' => 0, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Nothing was deleted going up, so everything comes back.
        DB::table('petty_cash_requisition_types')
            ->whereIn('code', self::SUPERSEDED)
            ->update(['is_active' => 1, 'updated_at' => now()]);

        $introduced = array_map(
            fn (string $code) => strtolower(str_replace('-', '_', $code)),
            array_keys(self::CATEGORIES),
        );

        // Only the ones nobody has used yet: a category that has already been
        // requested against stays, or its requisitions lose their type.
        $used = DB::table('petty_cash_requisitions')
            ->whereNotNull('requisition_type_id')
            ->pluck('requisition_type_id');

        DB::table('petty_cash_requisition_types')
            ->whereIn('code', $introduced)
            ->whereNotIn('id', $used)
            ->delete();
    }
};
