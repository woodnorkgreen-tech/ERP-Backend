<?php

namespace App\Modules\Finance\Database\Seeders;

use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * WNG expense catalogue.
 *
 * Three blocks, each in its own class so they stay readable:
 *
 * - direct materials, DM-WD/GL/PL/MT/PR/FN/DC/EL, below;
 * - the rest of project spend plus the common overheads, in
 *   {@see OperationalExpenseCodes} — labour, subcontractors, transport,
 *   equipment, utilities, facilitation, venue and rework, each pointing at the
 *   WIP child the chart of accounts already carries for it;
 * - NE-001…NE-023 non-expense cash movements, in {@see NonExpenseCodes}.
 *
 * Production overhead (6xxx) is now partly here. The six workshop, safety,
 * cleaning and utilities codes in {@see OperationalExpenseCodes} were added
 * because they are bought — a requisition for detergent or drill bits had
 * nothing to classify itself as — and each names a postable 6xxx account. What
 * is still absent is overhead *absorption*: spreading those costs across jobs
 * is a period-end calculation, not a capture choice, so no code describes it.
 *
 * Still absent entirely: the remaining capex codes. Those need the asset
 * register in the loop, so seeding them would add codes nothing can post.
 *
 * `default_debit_gl` is stored verbatim from the catalogue and the account FK is
 * resolved by reading the leading four-digit code out of it. Several rows name
 * an account indirectly ("Relevant 1400 PPE account", "Receiving cash/bank
 * account") — those keep the text and leave the FK null, which is exactly why
 * the schema carries both.
 */
class ExpenseCodeSeeder extends Seeder
{
    /** Shared by every direct-materials row; the catalogue repeats them verbatim. */
    private const MATERIAL_DEFAULTS = [
        'accounting_class' => 'Direct project cost',
        'expense_family' => 'Direct materials',
        'recording_rule' => 'Charge to the Job ID. Post to Project WIP while the job is open, then transfer to cost of sales when the related revenue is recognised.',
        'default_debit_gl' => '1211 Project WIP – Direct Materials',
        'job_id_rule' => ExpenseCode::JOB_REQUIRED,
        'job_id_rule_note' => 'Required',
        'default_cost_centre' => 'Production / Stores',
        'project_activity' => 'Fabrication / Production',
        'inventory_treatment' => 'Direct purchase to job or inventory issue',
        'vat_default' => 'Recoverable if eligible; otherwise add VAT to project cost',
        'default_vat_treatment_code' => 'STD16-REC',
        'wht_review' => 'Normally no; review unusual supplier/service bundles',
        'default_wht_category_code' => 'NONE',
        'key_control' => 'Compare with approved BOM/budget; record leftover return and wastage',
        'pl_report_line' => 'Cost of sales – Direct materials',
        'cash_flow_class' => 'operating',
        'minimum_evidence' => [
            ['key' => 'etims_invoice', 'label' => 'eTIMS invoice or receipt', 'required' => true],
            ['key' => 'delivery_note', 'label' => 'Delivery note / GRN or material issue note', 'required' => true],
            ['key' => 'purchase_order', 'label' => 'PO or approved request', 'required' => false],
        ],
        'extra_operational_data' => [
            ['key' => 'item_code', 'label' => 'Item code', 'type' => 'text', 'required' => true],
            ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'number', 'required' => true],
            ['key' => 'uom', 'label' => 'Unit of measure', 'type' => 'text', 'required' => true],
            ['key' => 'unit_price', 'label' => 'Unit price', 'type' => 'number', 'required' => true],
            ['key' => 'supplier_batch', 'label' => 'Supplier / batch', 'type' => 'text', 'required' => false],
            ['key' => 'receiver', 'label' => 'Received by', 'type' => 'text', 'required' => true],
        ],
    ];

    /** [code, expense_type, simple_meaning, example] */
    private const MATERIALS = [
        ['DM-WD-001', 'MDF boards', 'Medium-density fibreboard used to build counters, walls and display units.', '18 mm MDF used to fabricate an exhibition counter.'],
        ['DM-WD-002', 'Plywood boards', 'Plywood used for structures, flooring, crates or furniture.', '18 mm plywood used for a raised booth floor.'],
        ['DM-WD-003', 'Chipboard / particle board', 'Lower-cost engineered board used for selected furniture and display work.', 'White-faced chipboard used for internal shelves.'],
        ['DM-WD-004', 'OSB boards', 'Oriented strand board used where the board texture or strength is required.', 'OSB used for a rustic activation wall.'],
        ['DM-WD-005', 'Solid timber', 'Natural timber used for framing, furniture or finishing.', 'Planed pine used for a cabin-style activation.'],
        ['DM-WD-006', 'Timber battens and framing', 'Small timber sections used to make internal support frames.', '2 x 2 timber used behind a branded backwall.'],
        ['DM-WD-007', 'Laminate / veneer', 'Decorative finish applied to boards and furniture.', 'Mahogany laminate applied to a cigar-club wall.'],
        ['DM-WD-008', 'Edge banding', 'Thin finishing strip applied to exposed board edges.', 'White PVC edge band used on a lockable counter.'],
        ['DM-WD-009', 'Wood adhesive', 'Glue used to join timber, boards, laminates or edging.', 'Contact adhesive used to fix laminate.'],
        ['DM-WD-010', 'Hardware and fasteners', 'Screws, nails, bolts, hinges, locks, handles and brackets used in fabrication.', 'Hinges, lock and handles fitted to a storage counter.'],

        ['DM-GL-001', 'Glass', 'Glass used for counters, doors, display cases or booth walls.', 'Clear toughened glass for a consultation cubicle.'],
        ['DM-GL-002', 'Mirrors', 'Mirror panels used for décor, displays or custom installations.', '6 x 8 ft mirrors installed on a rooftop frame.'],

        ['DM-PL-001', 'Acrylic / Perspex', 'Clear or coloured plastic sheet used for signage, lightboxes and displays.', '3 mm opal acrylic face for a logo lightbox.'],
        ['DM-PL-002', 'PVC foam board', 'Light plastic board used for signage, cladding and printed panels.', '5 mm PVC board used for a branded menu panel.'],
        ['DM-PL-003', 'Foam board', 'Light display board used for indoor graphics and presentation panels.', '5 mm foam board for event directional signs.'],
        ['DM-PL-004', 'ACP / aluminium composite panel', 'Rigid composite sheet used for durable cladding and signage.', 'ACP skin on an outdoor activation kiosk.'],

        ['DM-MT-001', 'Mild-steel tubes', 'Steel square, rectangular or round tubes used to build frames.', '25 x 25 mm steel tube for an entrance arch.'],
        ['DM-MT-002', 'Steel angle / channel', 'Structural steel sections used for support frames and bases.', 'Steel angle used for a sliding gate base frame.'],
        ['DM-MT-003', 'Steel sheet / plate', 'Flat steel used for brackets, base plates, covers and fabricated parts.', '6 mm plate cut for truss base plates.'],
        ['DM-MT-004', 'Aluminium sections', 'Light metal profiles or sheets used for frames and display systems.', 'Aluminium profile used for a portable lightbox.'],
        ['DM-MT-005', 'Stainless-steel material', 'Corrosion-resistant metal used for premium finishes or wet areas.', 'Brushed stainless trim on a premium counter.'],
        ['DM-MT-006', 'Welding consumables', 'Welding rods, wire, gas, nozzles and related items consumed on the job.', 'MIG wire and shielding gas used to weld a stage frame.'],
        ['DM-MT-007', 'Cutting and grinding consumables', 'Discs, blades and bits consumed while cutting or finishing metal.', 'Grinding discs used to clean welded joints.'],

        ['DM-PR-001', 'Self-adhesive vinyl', 'Printed or coloured sticker material applied to boards, walls, glass or vehicles.', 'Printed vinyl applied to exhibition wall panels.'],
        ['DM-PR-002', 'Flex / banner material', 'Flexible printed material used for banners, skins and large-format branding.', 'Backlit flex used on a large outdoor lightbox.'],
        ['DM-PR-003', 'Printable fabric / textile', 'Fabric used for tension graphics, flags, draping or soft signage.', 'Dye-sublimated fabric used on a booth lightbox.'],
        ['DM-PR-004', 'Wallpaper / poster paper', 'Paper-based print media used for temporary indoor graphics.', 'Printed wallpaper installed on a showroom feature wall.'],
        ['DM-PR-005', 'Printing ink', 'Ink consumed to print project graphics.', 'UV ink used for direct printing on PVC panels.'],
        ['DM-PR-006', 'Lamination film', 'Clear protective film applied over printed graphics.', 'Matt laminate used on floor stickers.'],
        ['DM-PR-007', 'Application tape and transfer film', 'Temporary film used to install cut vinyl graphics.', 'Application tape used to install cut logo lettering.'],
        ['DM-PR-008', 'Eyelets, rope and banner finishing', 'Small items used to finish and hang banners.', 'Eyelets and rope fitted to a perimeter banner.'],

        ['DM-FN-001', 'Paint and primer', 'Paint systems used to colour and protect fabricated items.', 'Primer and corporate-blue paint used on a steel kiosk.'],
        ['DM-FN-002', 'Thinner and solvents', 'Liquids used to mix paint, clean equipment or remove adhesive.', 'Thinner used during spray finishing.'],
        ['DM-FN-003', 'Filler, putty and body filler', 'Material used to smooth joints, dents and surfaces before finishing.', 'Body filler used on a curved MDF structure.'],
        ['DM-FN-004', 'Sandpaper and abrasives', 'Sheets, discs and pads used to prepare surfaces.', 'Sandpaper used to prepare a painted counter.'],
        ['DM-FN-005', 'Brushes, rollers and masking materials', 'Small finishing tools and masking items consumed on one job.', 'Masking tape and rollers used during painting.'],

        ['DM-DC-001', 'Carpet and floor covering', 'Carpet, vinyl flooring or other finish installed for an event or booth.', 'Purple carpet laid on a raised exhibition floor.'],
        ['DM-DC-002', 'Artificial grass', 'Synthetic turf used for event floors or décor.', 'Artificial grass used in a sports activation zone.'],
        ['DM-DC-003', 'Décor fabric and draping', 'Decorative cloth used for curtains, drapes and soft finishes.', 'Maroon velvet curtains used at an event entrance.'],
        ['DM-DC-004', 'Artificial greenery and flowers', 'Decorative plants or flowers consumed or left with the client.', 'Greenery fixed to alternate stage backdrop panels.'],

        ['DM-EL-001', 'Electrical cable and connectors', 'Cables, plugs, connectors and trunking installed on a project.', 'Power cable and connectors used inside a branded kiosk.'],
        ['DM-EL-002', 'LED modules and strips', 'LED lighting installed in signs, counters or display structures.', 'LED modules installed behind a logo lightbox.'],
    ];

    public function run(): void
    {
        // Postable leaves only. "Relevant 1400 PPE account" names a header the
        // catalogue expects a human to pick a child of; resolving it to 1400
        // itself would hand the posting engine an account it cannot post to.
        $accounts = DB::table('chart_of_accounts')
            ->where('is_postable', true)
            ->pluck('id', 'code');

        DB::transaction(function () use ($accounts) {
            foreach ($this->rows() as $row) {
                $row['default_debit_account_id'] = $this->resolveAccount($row['default_debit_gl'] ?? null, $accounts);

                // A code without a concrete debit account is an accounting
                // instruction, not yet a postable capture choice (for example
                // "receiving bank account" or "relevant PPE account"). Leaving
                // it active made the journal service guess a generic expense
                // account: the entry balanced, but described the wrong event.
                // Keep the catalogue row for the future dedicated workflow,
                // while removing it from ordinary cost capture until Finance
                // gives it an explicit posting destination.
                $row['is_active'] = $row['default_debit_account_id'] !== null;

                ExpenseCode::updateOrCreate(['code' => $row['code']], $row);
            }
        });
    }

    /**
     * Reads the leading four-digit account code out of the catalogue's GL text.
     * Rows that name an account indirectly resolve to null and keep the text.
     */
    private function resolveAccount(?string $gl, $accounts): ?int
    {
        if (blank($gl) || ! preg_match('/\b(\d{4})\b/', $gl, $matches)) {
            return null;
        }

        return $accounts[$matches[1]] ?? null;
    }

    /** @return iterable<array<string, mixed>> */
    private function rows(): iterable
    {
        foreach (self::MATERIALS as [$code, $type, $meaning, $example]) {
            yield array_merge(self::MATERIAL_DEFAULTS, [
                'code' => $code,
                'expense_type' => $type,
                'simple_meaning' => $meaning,
                'example' => $example,
            ]);
        }

        yield [
            'code' => 'OE-FIN-001',
            // Posted automatically from the payment itself; nobody requests one.
            'is_procurable' => false,
            'accounting_class' => 'Operating expense',
            'expense_family' => 'Finance costs',
            'expense_type' => 'Bank and mobile-money transaction charges',
            'simple_meaning' => 'Fees charged by a bank, card provider or mobile-money operator for moving funds.',
            'example' => 'M-Pesa transfer charge on a supplier payment.',
            'recording_rule' => 'Post separately from the underlying purchase so cash and expense reconcile without misclassifying the fee.',
            'default_debit_gl' => '7800 Bank & Mobile-money Charges',
            'job_id_rule' => ExpenseCode::JOB_OPTIONAL,
            'job_id_rule_note' => 'Use the related Job ID when the fee is directly attributable to a project payment.',
            'default_cost_centre' => 'Finance',
            'project_activity' => 'Not Applicable',
            'vat_default' => 'No VAT unless separately evidenced by the provider.',
            'default_vat_treatment_code' => 'OOS',
            'wht_review' => 'No',
            'default_wht_category_code' => 'NONE',
            'key_control' => 'Must agree to the provider statement and the related payment reference.',
            'pl_report_line' => 'Operating expenses – Bank and mobile-money charges',
            'cash_flow_class' => 'operating',
            'minimum_evidence' => [],
            'extra_operational_data' => [],
        ];

        yield from OperationalExpenseCodes::rows();

        yield from NonExpenseCodes::rows();
    }
}
