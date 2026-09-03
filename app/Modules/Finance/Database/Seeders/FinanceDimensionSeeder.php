<?php

namespace App\Modules\Finance\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The four classification dimensions.
 *
 * Written with DB::table upserts keyed on `code` rather than through Eloquent:
 * these are reference tables whose authority is this file, they are re-run on
 * every deploy, and the models for them are deliberately not created until the
 * resolver chain needs them.
 *
 * DRAFT — cost centres in particular need Finance sign-off. The department list
 * they derive from had several ambiguous rows in the source roster, so this is a
 * starting point to correct, not a fact to build reports on.
 */
class FinanceDimensionSeeder extends Seeder
{
    /** [code, name, parent, sort] */
    private const COST_CENTRES = [
        ['ADMIN',  'Administration',            null,    10],
        ['HR',     'Human Resources',           'ADMIN', 11],
        ['FIN',    'Finance',                   'ADMIN', 12],
        ['IT',     'Information Technology',    'ADMIN', 13],

        ['OPS',    'Operations',                null,    20],
        ['PROD',   'Production',                'OPS',   21],
        ['PRINT',  'Printing',                  'OPS',   22],
        ['TECH',   'Technical',                 'OPS',   23],
        ['STORES', 'Stores',                    'OPS',   24],
        ['PROC',   'Procurement',               'OPS',   25],
        ['LOG',    'Logistics & Transport',     'OPS',   26],
        ['SANIT',  'Sanitation',                'OPS',   27],

        ['COMM',   'Commercial',                null,    30],
        ['PROJ',   'Projects',                  'COMM',  31],
        ['CREA',   'Creatives & Design',        'COMM',  32],
        ['CS',     'Client Service',            'COMM',  33],

        ['FAC',    'Facilities',                null,    40],
        ['HIRE',   'Hire Assets',               null,    50],
    ];

    /**
     * [code, name, workflow_task_type, cash_bearing, sort]
     *
     * The first block maps one-for-one onto the task types in
     * config/enquiry_workflow.php, so project activity and task state can never
     * drift apart — the collector reads the project's live stage and resolves
     * the activity from it rather than asking the user.
     *
     * `is_cash_bearing` marks the stages that actually carry disbursements. The
     * planning stages rarely do, and flagging them keeps the activity picker
     * short for the common case without removing them from reporting.
     */
    private const ACTIVITIES = [
        ['SITE-SURVEY',  'Site Survey',            'site-survey',    true,  10],
        ['DESIGN',       'Design & Concept',       'design',         false, 20],
        ['QUOTE',        'Quote Preparation',      'quote',          false, 30],
        ['QUOTE-APPR',   'Quote Approval',         'quote_approval', false, 40],
        ['MATERIALS',    'Material & Cost Listing','materials',      false, 50],
        ['BUDGET',       'Budget',                 'budget',         false, 60],
        ['PROCUREMENT',  'Procurement',            'procurement',    true,  70],
        ['TEAMS',        'Team Assignment',        'teams',          false, 80],
        ['PRODUCTION',   'Production',             'production',     true,  90],
        ['LOGISTICS',    'Logistics',              'logistics',      true, 100],
        ['SETUP',        'Setup & Installation',   'setup',          true, 110],
        ['HANDOVER',     'Handover',               'handover',       true, 120],
        ['SETDOWN',      'Set-down',               'setdown',        true, 130],
        ['REPORT',       'Project Report',         'report',         false,140],

        // Finer-grained production activities the catalogue names directly.
        ['FABRICATION',  'Fabrication',            null, true, 150],
        ['INSTALLATION', 'Installation',           null, true, 160],
        ['RECEIVING',    'Procurement / Receiving',null, true, 170],
        ['PROD-RETURN',  'Production Return',      null, false,180],
        ['ASSET-BUILD',  'Asset Build / Acquisition', null, true, 190],

        // Non-project activities. A cost still needs an activity even when it has
        // no Job ID, otherwise overhead and balance-sheet movements have nowhere
        // to sit in the same reporting structure.
        ['NA',           'Not Applicable',         null, false,900],
        ['TAX',          'Tax',                    null, false,910],
        ['ADMINISTRATION','Administration',        null, false,920],
        ['FINANCING',    'Financing',              null, false,930],
        ['CLIENT-BILL',  'Client Billing',         null, false,940],
        ['CAPITAL-PURCH','Capital Purchase',       null, false,950],
        ['CAPITAL-WORKS','Capital Works',          null, false,960],
        ['FIN-CLOSE',    'Financial Close',        null, false,970],
    ];

    /**
     * [code, name, is_exception, is_default, requires_note, sort, description]
     *
     * `is_exception` is what makes exception reporting dynamic: the variance
     * analysis groups on this flag, never on a hardcoded list of causes, so a
     * cause added next year appears in those reports without a code change.
     */
    private const COST_CAUSES = [
        ['PLANNED',       'Planned',        false, true,  false, 10,
            'Budgeted work proceeding as scoped.'],
        ['CLIENT-CHANGE', 'Client Change',  true,  false, true,  20,
            'Scope changed at the client\'s request. Recorded as a variation and should be billable.'],
        ['EMERGENCY',     'Emergency',      true,  false, true,  30,
            'Unplanned urgent spend needed to keep delivery on track.'],
        ['REWORK',        'Rework',         true,  false, true,  40,
            'Work redone because the first attempt was not acceptable.'],
        ['BREAKDOWN',     'Breakdown',      true,  false, true,  50,
            'Equipment or vehicle failure forced the cost.'],
        ['WASTAGE',       'Wastage',        true,  false, true,  60,
            'Material lost, damaged or over-consumed against the budgeted quantity.'],
        ['WARRANTY',      'Warranty',       true,  false, true,  70,
            'Corrective work carried at WNG\'s cost after handover.'],
    ];

    /**
     * [code, name, kra_pin, wht_review, supplier_record, sort]
     *
     * These booleans drive capture-form validation directly, so introducing a
     * payee type that needs a KRA PIN is a row rather than a release.
     */
    private const PAYEE_TYPES = [
        ['SUPPLIER', 'Supplier',      true,  true,  true,  10],
        ['EMPLOYEE', 'Employee',      false, false, false, 20],
        ['CASUAL',   'Casual Worker', false, false, false, 30],
        ['AUTHORITY','Authority',     false, false, false, 40],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $now = now();

            foreach (self::COST_CENTRES as [$code, $name, , $sort]) {
                DB::table('cost_centres')->updateOrInsert(
                    ['code' => $code],
                    ['name' => $name, 'sort_order' => $sort, 'is_active' => true, 'updated_at' => $now, 'created_at' => $now],
                );
            }

            // Second pass, once every parent exists.
            $centreIds = DB::table('cost_centres')->pluck('id', 'code');
            foreach (self::COST_CENTRES as [$code, , $parent]) {
                DB::table('cost_centres')->where('code', $code)
                    ->update(['parent_id' => $parent ? ($centreIds[$parent] ?? null) : null]);
            }

            foreach (self::ACTIVITIES as [$code, $name, $taskType, $cashBearing, $sort]) {
                DB::table('activities')->updateOrInsert(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'workflow_task_type' => $taskType,
                        'is_cash_bearing' => $cashBearing,
                        'sort_order' => $sort,
                        'is_active' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }

            foreach (self::COST_CAUSES as [$code, $name, $exception, $default, $note, $sort, $description]) {
                DB::table('cost_causes')->updateOrInsert(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'description' => $description,
                        'is_exception' => $exception,
                        'is_default' => $default,
                        'requires_note' => $note,
                        'sort_order' => $sort,
                        'is_active' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }

            foreach (self::PAYEE_TYPES as [$code, $name, $pin, $wht, $supplier, $sort]) {
                DB::table('payee_types')->updateOrInsert(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'requires_kra_pin' => $pin,
                        'requires_wht_review' => $wht,
                        'requires_supplier_record' => $supplier,
                        'sort_order' => $sort,
                        'is_active' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }
        });
    }
}
