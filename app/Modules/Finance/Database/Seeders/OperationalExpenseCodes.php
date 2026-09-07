<?php

namespace App\Modules\Finance\Database\Seeders;

use App\Modules\Finance\CostCollector\Models\ExpenseCode;

/**
 * The non-material project spend: labour, subcontractors, transport, equipment,
 * utilities, facilitation, venue and rework — plus the handful of operating
 * expenses people actually requisition for.
 *
 * WHY THESE AND NOT OTHERS
 *
 * Nothing here is invented. The chart of accounts already carries a WIP child
 * and a matching cost-of-sales child for each of these eight families
 * (1212–1219 against 5200–5900), and that pairing is what defines the family
 * list. ExpenseCodeSeeder's note that "their codes and GL accounts have not
 * been supplied" was true of the codes only — the accounts were there all
 * along, unreferenced by any catalogue row.
 *
 * WHAT THIS FIXES
 *
 * A project budget has four categories and the catalogue could classify one.
 * Measured on the current data: materials 5,325 lines, labour 431, logistics
 * 318, expenses 104 — and because CostCollectorService throws on an unknown
 * code, three of those four could not be captured at all.
 *
 * EVIDENCE IS DELIBERATELY LIGHT
 *
 * The direct-materials rows demand an eTIMS invoice AND a delivery note, plus
 * five typed fields. That is right for a stores receipt against a PO, and wrong
 * for someone standing beside a truck with a phone. These rows ask for the one
 * document that is the actual control — a receipt, a signed muster sheet, a
 * subcontractor invoice — and nothing else.
 *
 * WITHHOLDING IS LEFT TO THE SUPPLIER RECORD
 *
 * Service families carry a NULL `default_wht_category_code` on purpose.
 * TaxResolver reads the code first and only consults the supplier when the code
 * says nothing, so naming a category here would either suppress legitimate
 * withholding (NONE on a subcontractor) or withhold 3% from a taxi fare. Null
 * lets the supplier's own category decide, which is the record that knows.
 * Goods-like rows keep NONE, because goods are not withheld against.
 */
class OperationalExpenseCodes
{
    /** Shared by every project-cost row; only the family details differ. */
    private const PROJECT_DEFAULTS = [
        'accounting_class' => 'Direct project cost',
        'job_id_rule' => ExpenseCode::JOB_REQUIRED,
        'job_id_rule_note' => 'Required',
        'inventory_treatment' => 'Not inventory — consumed on the job',
        'cash_flow_class' => 'operating',
        'vat_default' => 'Recoverable if the supplier is VAT registered and an eTIMS invoice is held.',
        'default_vat_treatment_code' => 'STD16-REC',
    ];

    /** One receipt. The minimum defensible control for phone capture. */
    private const RECEIPT_ONLY = [
        ['key' => 'receipt', 'label' => 'Receipt or invoice', 'required' => true],
    ];

    /**
     * [family, wip_gl, cost_centre, activity, pl_line, wht_code, evidence, fields, codes[]]
     * where each code is [code, expense_type, simple_meaning, example].
     */
    private const FAMILIES = [
        [
            'family' => 'Direct labour',
            'gl' => '1212 Project WIP – Direct Labour',
            'cost_centre' => 'Production / Site',
            'activity' => 'Fabrication / Production',
            'pl_line' => 'Cost of sales – Direct labour',
            'wht' => 'NONE',
            'control' => 'Agree headcount and days to the signed muster sheet before payment.',
            'evidence' => [
                ['key' => 'muster_sheet', 'label' => 'Signed muster / attendance sheet', 'required' => true],
            ],
            'fields' => [
                ['key' => 'worker_count', 'label' => 'Number of people', 'type' => 'number', 'required' => true],
                ['key' => 'days_worked', 'label' => 'Days worked', 'type' => 'number', 'required' => true],
            ],
            'codes' => [
                ['DL-CAS-001', 'Casual site labour', 'Day-rate workers engaged for a specific job.', 'Six casuals for two days rigging an exhibition stand.'],
                ['DL-CAS-002', 'Casual workshop labour', 'Day-rate workers assisting fabrication in the workshop.', 'Four casuals sanding and finishing counters before dispatch.'],
                ['DL-ALW-001', 'Site allowance and overtime', 'Allowances paid to staff for site work outside normal hours.', 'Night-shift allowance during an overnight build.'],
            ],
        ],
        [
            'family' => 'Subcontractors',
            'gl' => '1213 Project WIP – Subcontractors',
            'cost_centre' => 'Production / Site',
            'activity' => 'Fabrication / Production',
            'pl_line' => 'Cost of sales – Subcontractors',
            'wht' => null,
            'control' => 'Match to the subcontract scope and confirm the work was completed before release.',
            'evidence' => [
                ['key' => 'etims_invoice', 'label' => 'Subcontractor invoice', 'required' => true],
                ['key' => 'work_completion', 'label' => 'Completion or sign-off note', 'required' => false],
            ],
            'fields' => [],
            'codes' => [
                ['SC-FAB-001', 'Fabrication subcontract', 'Structural or joinery work bought in from another workshop.', 'Metal truss arch fabricated by an external welder.'],
                ['SC-INS-001', 'Specialist installation', 'Installers engaged for rigging, electrical or height work.', 'Certified riggers hanging a suspended banner.'],
                ['SC-PRN-001', 'Print subcontract', 'Large-format or specialist printing bought from a trade printer.', 'Backlit fabric printed by a trade supplier.'],
                ['SC-PRO-001', 'Professional and consultancy fees', 'Design, engineering or advisory services bought for a job.', 'Structural sign-off for a raised platform.'],
            ],
        ],
        [
            'family' => 'Transport and logistics',
            'gl' => '1214 Project WIP – Transport & Logistics',
            'cost_centre' => 'Logistics',
            'activity' => 'Logistics / Delivery',
            'pl_line' => 'Cost of sales – Transport and logistics',
            'wht' => null,
            'control' => 'Agree to the delivery route and the job it served.',
            'evidence' => self::RECEIPT_ONLY,
            'fields' => [
                ['key' => 'route', 'label' => 'From / to', 'type' => 'text', 'required' => false],
                ['key' => 'vehicle_reg', 'label' => 'Vehicle registration', 'type' => 'text', 'required' => false],
            ],
            'codes' => [
                ['TL-HIR-001', 'Truck and vehicle hire', 'Third-party vehicles hired to move materials or a build.', 'Seven-tonne truck hired to deliver a stand to a venue.'],
                ['TL-FUE-001', 'Fuel for project transport', 'Fuel used moving materials, crew or equipment for a job.', 'Diesel for the company truck on a site delivery run.'],
                ['TL-CRW-001', 'Crew transport', 'Moving the build or event crew to and from site.', 'Matatu hire for eight crew to an out-of-town activation.'],
                ['TL-CUR-001', 'Courier and dispatch', 'Small consignments sent by courier for a job.', 'Courier of printed graphics to a client site upcountry.'],
                ['TL-HND-001', 'Loading and handling', 'Casual handling, porterage or offloading at either end.', 'Offloading crew paid at the venue loading bay.'],
            ],
        ],
        [
            'family' => 'Equipment and site',
            'gl' => '1215 Project WIP – Equipment & Site',
            'cost_centre' => 'Production / Site',
            'activity' => 'Site Works',
            'pl_line' => 'Cost of sales – Equipment and site',
            'wht' => null,
            'control' => 'Confirm the hire period against the build and dismantle dates.',
            'evidence' => self::RECEIPT_ONLY,
            'fields' => [
                ['key' => 'hire_days', 'label' => 'Hire days', 'type' => 'number', 'required' => false],
            ],
            'codes' => [
                ['EQ-HIR-001', 'Equipment hire', 'Plant or machinery hired for a build.', 'Boom lift hired to install high-level signage.'],
                ['EQ-SCF-001', 'Scaffolding and access hire', 'Temporary access structures hired for a job.', 'Scaffold tower for a two-storey backdrop.'],
                ['EQ-GEN-001', 'Generator hire', 'Temporary power plant hired for a site or event.', 'Silent generator for an outdoor activation.'],
                ['EQ-TOL-001', 'Site tools and consumables', 'Small tools and consumables bought for one job.', 'Blades, fixings and tape bought on site mid-build.'],
                ['EQ-SAF-001', 'Site safety and PPE', 'Protective equipment and safety provision for a build.', 'Harnesses and helmets for a rigging crew.'],
            ],
        ],
        [
            'family' => 'Project utilities',
            'gl' => '1216 Project WIP – Project Utilities',
            'cost_centre' => 'Production / Site',
            'activity' => 'Site Works',
            'pl_line' => 'Cost of sales – Project utilities',
            'wht' => null,
            'control' => 'Agree to the venue or landlord charge for the build period.',
            'evidence' => self::RECEIPT_ONLY,
            'fields' => [],
            'codes' => [
                ['PU-PWR-001', 'Site power', 'Electricity drawn or bought at a build or event site.', 'Venue power connection charge for a three-day exhibition.'],
                ['PU-FUE-001', 'Generator fuel', 'Fuel consumed by temporary power plant on site.', 'Diesel for the event generator over two nights.'],
                ['PU-WTR-001', 'Site water and sanitation', 'Water and sanitary provision for a build or event.', 'Portable toilets for a field activation.'],
                ['PU-WST-001', 'Site waste removal', 'Clearing and disposing of build waste.', 'Skip hire to clear offcuts after a stand build.'],
            ],
        ],
        [
            'family' => 'Project facilitation',
            'gl' => '1217 Project WIP – Project Facilitation',
            'cost_centre' => 'Production / Site',
            'activity' => 'Site Works',
            'pl_line' => 'Cost of sales – Project facilitation',
            'wht' => 'NONE',
            'control' => 'Agree headcount and dates to the crew actually on site.',
            'evidence' => self::RECEIPT_ONLY,
            'fields' => [
                ['key' => 'people_covered', 'label' => 'People covered', 'type' => 'number', 'required' => false],
            ],
            'codes' => [
                ['PF-MEA-001', 'Site meals and refreshments', 'Feeding the build or event crew while on site.', 'Lunch for ten crew during an overnight build.'],
                ['PF-ACC-001', 'Crew accommodation', 'Lodging for crew working away from base.', 'Two nights lodging for four crew at a coastal activation.'],
                ['PF-PDM-001', 'Out-of-town per diem', 'Subsistence paid to crew working away from base.', 'Per diem for a three-day upcountry install.'],
            ],
        ],
        [
            'family' => 'Venue and statutory',
            'gl' => '1218 Project WIP – Venue & Statutory',
            'cost_centre' => 'Projects',
            'activity' => 'Site Works',
            'pl_line' => 'Cost of sales – Venue and statutory',
            'wht' => null,
            'control' => 'Hold the permit or venue invoice naming the event and dates.',
            'evidence' => self::RECEIPT_ONLY,
            'fields' => [],
            'codes' => [
                ['VS-VEN-001', 'Venue and space hire', 'Paying for the space an event or build occupies.', 'Exhibition hall space hired for a client stand.'],
                ['VS-PRM-001', 'County permits and licences', 'Statutory permissions required to build or trade at a site.', 'County permit for an outdoor branding installation.'],
                ['VS-SEC-001', 'Site security', 'Guarding a build or stored materials at a venue.', 'Overnight guard for a stand left standing between show days.'],
                ['VS-INS-001', 'Event insurance', 'Cover taken out for a specific event or installation.', 'Public liability cover for a two-day activation.'],
            ],
        ],
        [
            'family' => 'Rework and warranty',
            'gl' => '1219 Project WIP – Rework & Warranty',
            'cost_centre' => 'Production / Site',
            'activity' => 'Fabrication / Production',
            'pl_line' => 'Cost of sales – Rework and warranty',
            'wht' => null,
            'control' => 'Record the cause — rework is the number the production review exists to see.',
            'evidence' => self::RECEIPT_ONLY,
            'fields' => [
                ['key' => 'rework_reason', 'label' => 'What went wrong', 'type' => 'textarea', 'required' => true],
            ],
            'codes' => [
                ['RW-MAT-001', 'Rework materials', 'Materials bought again to correct a defect or damage.', 'Replacement acrylic after a panel cracked in transit.'],
                ['RW-LAB-001', 'Rework labour', 'Labour spent correcting a defect rather than building.', 'Two casuals refinishing a counter rejected on snagging.'],
                ['RW-SNG-001', 'Snag rectification on site', 'Putting right defects found at or after handover.', 'Returning to a venue to re-fix loose edge banding.'],
            ],
        ],
    ];

    /**
     * The spend that is bought but never charged to a job.
     *
     * This block used to hold three codes, and that was the whole non-project
     * catalogue: an office or stores requisition — one raised with no project
     * against it — could choose between office transport, airtime and staff
     * welfare, plus four balance-sheet rows (a stores replenishment, two
     * prepayments and a leasehold improvement) that the requester reads as
     * accounting jargon rather than as things to buy. Seven choices, four of
     * which do not sound like purchases, for every requisition the company
     * raises that is not against a job.
     *
     * So the everyday ones are named. Nothing here is invented either: each row
     * points at an account the chart already carries and had never referenced —
     * 6400 small tools, 6200 machinery repairs, 6600 PPE, 6700 cleaning and
     * waste, 6100 workshop electricity, 7100 office rent. The one exception is
     * 7150 Office Supplies & Stationery, added to the chart alongside this
     * because the gap the previous note here left visible ("the chart has no
     * account for them") is the single most requisitioned thing in the office,
     * and leaving it visible for another year serves nobody.
     *
     * Two gaps stay open rather than get guessed at. General premises repair
     * has no account of its own — 6200 is machinery by name — and workshop rent
     * (6110) is met by NE-011 prepaid rent today. Both are a chart decision,
     * and the September 2026 requisition-type alignment retired the folk
     * categories "Office Supplies" and "Repair & Maintenance" for exactly this
     * reason. The first of those two now has its code; the second still does
     * not, and saying so is better than filing it somewhere plausible.
     *
     * PRODUCTION OVERHEAD IS CAPTURED NOW, NOT ABSORBED
     *
     * ExpenseCodeSeeder's note that 6xxx codes were absent because "production
     * overhead is absorbed rather than captured" was about allocation, not
     * about buying. Absorption decides how a cleaning bill reaches a job's
     * margin; it does not stop Stores raising a requisition for detergent. The
     * six 6xxx rows below are purchases with a real postable account, and they
     * carry `job_id_rule` NOT_ALLOWED, so nothing here can be charged to a job
     * by mistake — which is the invariant absorption actually depends on.
     *
     * [code, type, meaning, example, gl, family, cost_centre, pl_line, vat]
     */
    private const OVERHEADS = [
        // Office running costs.
        ['OE-TRP-001', 'Office and admin transport', 'Travel that is not attributable to a client job.', 'Taxi to a bank or statutory office.',
            '7400 Office Transport', 'Staff and administration', 'Finance / Admin', 'Operating expenses – Office transport', 'STD16-REC'],
        ['OE-COM-001', 'Airtime and internet', 'Communications for administration rather than a job.', 'Monthly office internet subscription.',
            '7200 Administration Airtime & Internet', 'Staff and administration', 'Finance / Admin', 'Operating expenses – Airtime and internet', 'STD16-REC'],
        ['OE-WEL-001', 'Staff welfare', 'Provision for staff that is not chargeable to a job.', 'Tea and refreshments for the office.',
            '7600 Staff Welfare', 'Staff and administration', 'Finance / Admin', 'Operating expenses – Staff welfare', 'OOS'],

        // What the office actually requisitions most often.
        ['OE-OFF-001', 'Office supplies and stationery', 'Paper, pens, files and general office consumables.', 'Reams of paper and box files restocked for the office.',
            '7150 Office Supplies & Stationery', 'Office supplies', 'Finance / Admin', 'Operating expenses – Office supplies and stationery', 'STD16-REC'],
        ['OE-OFF-002', 'Printer and IT consumables', 'Toner, cartridges and small IT items consumed in the office.', 'Replacement toner for the office printer.',
            '7150 Office Supplies & Stationery', 'Office supplies', 'Finance / Admin', 'Operating expenses – Office supplies and stationery', 'STD16-REC'],

        // Workshop upkeep. The job-costed twins of these are EQ-TOL-001 site
        // tools and EQ-SAF-001 site safety; these are the stock-for-everyone
        // versions, which is why they forbid a job number rather than share one
        // code with a rule that bends.
        ['OE-WSC-001', 'Workshop consumables and small tools', 'Consumables and small tools restocked for the workshop rather than bought for one job.', 'Blades, drill bits and glue restocked for general workshop use.',
            '6400 Small Tools & Workshop Consumables', 'Workshop and maintenance', 'Production / Stores', 'Production overhead – Small tools and workshop consumables', 'STD16-REC'],
        ['OE-MNT-001', 'Machinery repair and servicing', 'Repairs, servicing and spare parts for workshop machinery.', 'Bearing replacement and service on the panel saw.',
            '6200 Machinery Repairs & Maintenance', 'Workshop and maintenance', 'Production / Workshop', 'Production overhead – Machinery repairs and maintenance', 'STD16-REC'],
        ['OE-PPE-001', 'Protective equipment and workshop safety', 'Protective gear and safety provision held for staff generally, not issued to one job.', 'Overalls, gloves and safety boots restocked for the workshop.',
            '6600 PPE & Workshop Safety', 'Safety and protective equipment', 'Production / Stores', 'Production overhead – PPE and workshop safety', 'STD16-REC'],

        // Keeping the premises running. This family deliberately straddles the
        // 6xxx/7xxx line — the workshop meter is production overhead, the office
        // one is operating expense — because a requester buying a power token is
        // not making that distinction, and the account each code posts to makes
        // it for them. It is the only family that does; see the families endpoint.
        ['OE-CLN-001', 'Cleaning supplies and services', 'Cleaning materials and contracted cleaning for the workshop or office.', 'Detergent, mops and the monthly office cleaning contract.',
            '6700 Cleaning & Waste Disposal', 'Cleaning and waste', 'Facilities', 'Production overhead – Cleaning and waste disposal', 'STD16-REC'],
        ['OE-WST-001', 'Waste collection and disposal', 'Removing general waste from the workshop or office premises.', 'Monthly skip collection from the workshop yard.',
            '6700 Cleaning & Waste Disposal', 'Cleaning and waste', 'Facilities', 'Production overhead – Cleaning and waste disposal', 'STD16-REC'],
        ['OE-UTL-001', 'Workshop electricity', 'Power drawn at the workshop, not attributable to one job.', 'Monthly workshop electricity bill or a prepaid token purchase.',
            '6100 Workshop Electricity', 'Premises and utilities', 'Production / Workshop', 'Production overhead – Workshop electricity', 'STD16-REC'],
        ['OE-UTL-002', 'Office rent and electricity', 'Rent and power for the office premises.', 'Monthly office rent invoice.',
            '7100 Office Rent & Electricity', 'Premises and utilities', 'Finance / Admin', 'Operating expenses – Office rent and electricity', 'STD16-REC'],
    ];

    /** @return iterable<array<string, mixed>> */
    public static function rows(): iterable
    {
        $sort = 100;

        foreach (self::FAMILIES as $family) {
            foreach ($family['codes'] as [$code, $type, $meaning, $example]) {
                yield array_merge(self::PROJECT_DEFAULTS, [
                    'code' => $code,
                    // Paid to staff through fund requisitions or payroll.
                    'is_procurable' => ! in_array($code, ['DL-ALW-001', 'PF-PDM-001'], true),
                    'expense_family' => $family['family'],
                    'expense_type' => $type,
                    'simple_meaning' => $meaning,
                    'example' => $example,
                    'recording_rule' => 'Charge to the Job ID. Post to Project WIP while the job is open, then transfer to cost of sales when the related revenue is recognised.',
                    'default_debit_gl' => $family['gl'],
                    'default_cost_centre' => $family['cost_centre'],
                    'project_activity' => $family['activity'],
                    'pl_report_line' => $family['pl_line'],
                    'wht_review' => $family['wht'] === 'NONE'
                        ? 'No'
                        : 'Yes — priced from the supplier record at verification.',
                    'default_wht_category_code' => $family['wht'],
                    'key_control' => $family['control'],
                    'minimum_evidence' => $family['evidence'],
                    'extra_operational_data' => $family['fields'],
                    'sort_order' => $sort += 10,
                ]);
            }
        }

        foreach (self::OVERHEADS as [$code, $type, $meaning, $example, $gl, $familyName, $centre, $plLine, $vat]) {
            yield [
                'code' => $code,
                // Read off the account rather than carried in the tuple: the 6xxx
                // and 7xxx ranges already draw this line, and a tenth column would
                // let a row disagree with the account it posts to.
                'accounting_class' => str_starts_with($gl, '6') ? 'Production overhead' : 'Operating expense',
                'expense_family' => $familyName,
                'expense_type' => $type,
                'simple_meaning' => $meaning,
                'example' => $example,
                'recording_rule' => str_starts_with($gl, '6')
                    ? 'Post to production overhead. Not a project cost — it is absorbed across jobs, never charged to one.'
                    : 'Post directly to the operating expense account. Not a project cost.',
                'default_debit_gl' => $gl,
                'job_id_rule' => ExpenseCode::JOB_NOT_ALLOWED,
                'job_id_rule_note' => 'Not allowed — this is overhead, not a job cost.',
                'default_cost_centre' => $centre,
                'project_activity' => 'Not Applicable',
                'vat_default' => 'Recoverable only where a valid eTIMS invoice is held.',
                'default_vat_treatment_code' => $vat,
                'wht_review' => 'No',
                'default_wht_category_code' => 'NONE',
                'key_control' => 'Must agree to a supporting receipt and the period it relates to.',
                'pl_report_line' => $plLine,
                'cash_flow_class' => 'operating',
                'minimum_evidence' => self::RECEIPT_ONLY,
                'extra_operational_data' => [],
                'sort_order' => $sort += 10,
            ];
        }
    }
}
