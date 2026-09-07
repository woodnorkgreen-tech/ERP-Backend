<?php

namespace App\Modules\Finance\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
    /**
     * [code, name, parent, sort, hr_department_name]
     *
     * The fifth element is what makes a cost centre reachable from anything the
     * rest of the ERP knows. `cost_centres.hr_department_id` has existed since
     * the dimension tables were created, documented as the thing that "lets the
     * collector default a cost centre from the submitting user's HR department"
     * — and it was never populated by anything, so every cost line ever recorded
     * carried a null cost centre while eighteen cost centres sat unreferenced.
     *
     * Matched by department NAME rather than id: ids are assigned by whatever
     * order DepartmentSeeder happened to run in, and this file must stay
     * meaningful in a database seeded in a different order. A name that does not
     * resolve leaves the link null rather than failing — Finance can still run
     * without the HR module present, which is why the column is unconstrained.
     *
     * Every one of WNG's thirteen departments now has exactly one cost centre.
     * The rows with no department are the roll-up parents (ADMIN/OPS/COMM) and
     * the cost pools that are not anybody's department — a hire fleet and a
     * building are spent on, not staffed.
     */
    private const COST_CENTRES = [
        ['ADMIN',  'Administration',            null,    10, null],
        ['HR',     'Human Resources',           'ADMIN', 11, 'Human Resource'],
        ['FIN',    'Finance',                   'ADMIN', 12, 'Accounts/Finance'],
        ['IT',     'Information Technology',    'ADMIN', 13, 'ICT'],

        ['OPS',    'Operations',                null,    20, null],
        ['PROD',   'Production',                'OPS',   21, 'Production'],
        ['PRINT',  'Printing',                  'OPS',   22, null],
        ['BRAND',  'Branding',                  'OPS',   23, 'Branding'],
        ['TECH',   'Technical',                 'OPS',   24, null],
        ['STORES', 'Stores',                    'OPS',   25, 'Stores'],
        ['PROC',   'Procurement',               'OPS',   26, 'Procurement'],
        ['LOG',    'Logistics & Transport',     'OPS',   27, 'Logistics'],
        ['CREW',   'Teams & Crew',              'OPS',   28, 'Teams'],
        ['SANIT',  'Sanitation',                'OPS',   29, null],

        ['COMM',   'Commercial',                null,    30, null],
        ['PROJ',   'Projects',                  'COMM',  31, 'Projects'],
        ['CREA',   'Creatives & Design',        'COMM',  32, 'Design/Creatives'],
        ['CS',     'Client Service',            'COMM',  33, 'Client Service'],
        ['COST',   'Costing & Estimation',      'COMM',  34, 'Costing'],

        ['FAC',    'Facilities',                null,    40, null],
        ['HIRE',   'Hire Assets',               null,    50, null],
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

            // Departments keyed by name. Absent entirely when Finance is seeded
            // without the HR module, which leaves every link null and is a
            // supported state rather than a failure.
            $departments = Schema::hasTable('departments')
                ? DB::table('departments')->pluck('id', 'name')
                : collect();

            foreach (self::COST_CENTRES as [$code, $name, , $sort, $department]) {
                DB::table('cost_centres')->updateOrInsert(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'hr_department_id' => $department ? ($departments[$department] ?? null) : null,
                        'sort_order' => $sort,
                        'is_active' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
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
