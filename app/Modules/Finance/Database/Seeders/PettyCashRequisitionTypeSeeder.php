<?php

namespace App\Modules\Finance\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The categories a fund requisition can be raised under.
 *
 * ## Why this is a seeder now
 *
 * Every other piece of Finance reference data is defined by an idempotent
 * seeder that re-runs on every deploy — that is what makes these files the
 * authority rather than whatever happens to be in a given database. This list
 * was the exception: it lived in four migrations
 * (`2026_08_23_000002`, `2026_09_05_000001`, `2026_09_05_000002`,
 * `2026_09_06_000001`), and migrations run once and are never re-asserted.
 *
 * So `db:seed` refreshed the expense catalogue these categories are derived
 * FROM while leaving the categories themselves frozen at whatever the last
 * migration wrote. Two mechanisms for the same kind of data, one of which
 * silently stopped tracking the other — which is the same failure the alignment
 * migration was written to undo at the vocabulary level.
 *
 * The migrations stay as they are. They have run, they are the history of how
 * this list got here, and re-running their logic through this seeder produces
 * the identical result because both are keyed on `code`.
 *
 * ## The derivation
 *
 * A category IS an expense code, named the way a requester would say it. That
 * is the point of the 2026-09-05 alignment: there is no second vocabulary to
 * keep in step, `requires_project` is read from the code's `job_id_rule` rather
 * than set by hand beside it, and a commitment raised on approval arrives
 * already classified.
 *
 * Only codes genuinely spent out of the cash tin are listed. Equipment hire,
 * subcontractors, professional fees, insurance and venue hire are supplier
 * invoiced and belong on a purchase order.
 *
 * `default_payment_source_id` is deliberately NOT set. It prefills the payment
 * source on the disbursement form, and which float or account a given category
 * is usually paid from is an operational decision with a screen of its own at
 * /finance/setup/requisition-types — the same reason PaymentSourceSeeder leaves
 * custodians and float limits to Finance rather than asserting them.
 */
class PettyCashRequisitionTypeSeeder extends Seeder
{
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
     * `requires_project` is absent on purpose: it is read from the expense
     * code's `job_id_rule` in run(), so the two can never disagree.
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
     * The folk categories these replaced, retired rather than deleted.
     *
     * A used category must keep existing or the requisitions raised under it
     * lose their type. Deactivating removes it from the picker just as
     * completely and keeps the questions an administrator wrote on it.
     */
    private const SUPERSEDED = [
        'projects', 'office_supplies', 'transport', 'meals',
        'repair_maintenance', 'fuel_lubricants', 'communication_airtime', 'miscellaneous',
    ];

    public function run(): void
    {
        // Finance can be seeded without the petty cash module's tables present.
        if (! Schema::hasTable('petty_cash_requisition_types')) {
            return;
        }

        $codes = DB::table('expense_codes')
            ->whereIn('code', array_keys(self::CATEGORIES))
            ->get(['id', 'code', 'job_id_rule'])
            ->keyBy('code');

        $now = now();
        $sort = 0;

        foreach (self::CATEGORIES as $code => [$name, $recipientMode, $requestFields]) {
            // A code the catalogue does not carry is skipped rather than
            // guessed at: an unresolvable category is worse than none.
            if (! $codes->has($code)) {
                continue;
            }

            $key = strtolower(str_replace('-', '_', $code));
            $sort++;

            // Unlike every other seeder in this module, this one does NOT
            // restate the whole row on each run.
            //
            // These types have an editor of their own at
            // /finance/setup/requisition-types: an administrator renames a
            // category, adds a question to it, reorders the list, or switches
            // one off. A blind upsert on every deploy would silently undo all of
            // that, which is precisely the reason reference data with a screen
            // behind it usually cannot be seeded at all.
            //
            // So authority is split along the line that already exists in the
            // design. Below, the two fields that MUST follow the catalogue,
            // because the whole point of deriving a category from an expense
            // code is that they cannot drift.
            DB::table('petty_cash_requisition_types')
                ->where('code', $key)
                ->update([
                    'default_expense_code_id' => $codes[$code]->id,
                    'requires_project' => $codes[$code]->job_id_rule === 'required' ? 1 : 0,
                    'updated_at' => $now,
                ]);

            // And here, everything an administrator owns — written once when the
            // category is first created and never touched again.
            DB::table('petty_cash_requisition_types')->insertOrIgnore([
                'code' => $key,
                'name' => $name,
                'recipient_mode' => $recipientMode,
                'requires_project' => $codes[$code]->job_id_rule === 'required' ? 1 : 0,
                'default_expense_code_id' => $codes[$code]->id,
                'request_fields' => $requestFields ? json_encode($requestFields) : null,
                'is_active' => 1,
                'sort_order' => $sort,
                'updated_at' => $now,
                'created_at' => $now,
            ]);
        }

        // Retired only on the way in. Re-running must not switch a superseded
        // category back off after somebody deliberately revived it.
        DB::table('petty_cash_requisition_types')
            ->whereIn('code', self::SUPERSEDED)
            ->whereNull('default_expense_code_id')
            ->update(['is_active' => 0, 'updated_at' => $now]);
    }
}
