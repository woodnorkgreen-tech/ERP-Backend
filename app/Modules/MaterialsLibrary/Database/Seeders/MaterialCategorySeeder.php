<?php

namespace App\Modules\MaterialsLibrary\Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;

class MaterialCategorySeeder extends Seeder
{
    /**
     * Full category taxonomy for the Materials Library.
     *
     * Each child carries a `code` (3-6 char uppercase) used as the middle
     * segment of auto-generated SKUs: {WORKSTATION}-{CODE}-{SEQUENCE}
     * e.g. CNC-MDF-0001, LFP-VNL-0015, CARP-PLY-0003
     *
     * Parent categories do not have codes — SKUs are always tied to the leaf.
     * The StoreBoardRequest gates board tracking by parent name (Boards / Sheet
     * Materials / Veneer), so parent names must not change.
     */
    public function run(): void
    {
        $taxonomy = [
            // ── Board-eligible parents ────────────────────────────────────────
            [
                'name'       => 'Boards',
                'sort_order' => 1,
                'children'   => [
                    ['name' => 'MDF Boards',        'code' => 'MDF',  'sort_order' => 1],
                    ['name' => 'Plywood',            'code' => 'PLY',  'sort_order' => 2],
                    ['name' => 'PVC Foam Boards',    'code' => 'PVC',  'sort_order' => 3],
                    ['name' => 'Chipboard',          'code' => 'CPB',  'sort_order' => 4],
                    ['name' => 'Blockboard',         'code' => 'BLK',  'sort_order' => 5],
                ],
            ],
            [
                'name'       => 'Sheet Materials',
                'sort_order' => 2,
                'children'   => [
                    ['name' => 'Acrylic Sheets',     'code' => 'ACR',  'sort_order' => 1],
                    ['name' => 'ACP Panels',         'code' => 'ACP',  'sort_order' => 2],
                    ['name' => 'Aluminium Composite','code' => 'ALX',  'sort_order' => 3],
                    ['name' => 'Forex PVC Sheets',   'code' => 'FPV',  'sort_order' => 4],
                    ['name' => 'Polycarbonate',      'code' => 'PCB',  'sort_order' => 5],
                    ['name' => 'Correx Sheets',      'code' => 'CRX',  'sort_order' => 6],
                ],
            ],
            [
                'name'       => 'Veneer',
                'sort_order' => 3,
                'children'   => [
                    ['name' => 'Wood Veneer',        'code' => 'WVN',  'sort_order' => 1],
                    ['name' => 'Melamine Veneer',    'code' => 'MVN',  'sort_order' => 2],
                    ['name' => 'HPL Laminate',       'code' => 'HPL',  'sort_order' => 3],
                ],
            ],

            // ── Consumable parents ────────────────────────────────────────────
            [
                'name'       => 'Printing Media',
                'sort_order' => 4,
                'children'   => [
                    ['name' => 'Vinyl Media',        'code' => 'VNL',  'sort_order' => 1],
                    ['name' => 'Banner & Mesh',      'code' => 'BNR',  'sort_order' => 2],
                    ['name' => 'Canvas & Fabric',    'code' => 'CVS',  'sort_order' => 3],
                    ['name' => 'Backlit Film',       'code' => 'BLF',  'sort_order' => 4],
                    ['name' => 'One-Way Vision',     'code' => 'OWV',  'sort_order' => 5],
                ],
            ],
            [
                'name'       => 'Inks & Coatings',
                'sort_order' => 5,
                'children'   => [
                    ['name' => 'Solvent Inks',       'code' => 'SVI',  'sort_order' => 1],
                    ['name' => 'UV Inks',            'code' => 'UVI',  'sort_order' => 2],
                    ['name' => 'Latex Inks',         'code' => 'LTX',  'sort_order' => 3],
                    ['name' => 'Primers & Sealers',  'code' => 'PRM',  'sort_order' => 4],
                    ['name' => 'Spray Paints',       'code' => 'SPT',  'sort_order' => 5],
                    ['name' => 'Clear Coats',        'code' => 'CLC',  'sort_order' => 6],
                ],
            ],
            [
                'name'       => 'Adhesives & Laminates',
                'sort_order' => 6,
                'children'   => [
                    ['name' => 'Mounting Adhesives', 'code' => 'MAD',  'sort_order' => 1],
                    ['name' => 'Double-Sided Tapes', 'code' => 'DST',  'sort_order' => 2],
                    ['name' => 'Laminating Film',    'code' => 'LMF',  'sort_order' => 3],
                    ['name' => 'Contact Adhesives',  'code' => 'CAD',  'sort_order' => 4],
                ],
            ],
            [
                'name'       => 'Metals & Profiles',
                'sort_order' => 7,
                'children'   => [
                    ['name' => 'Steel Sections',     'code' => 'SHS',  'sort_order' => 1],
                    ['name' => 'Aluminium Profiles', 'code' => 'ALP',  'sort_order' => 2],
                    ['name' => 'Welding Consumables','code' => 'WLD',  'sort_order' => 3],
                    ['name' => 'Cutting Discs',      'code' => 'CTD',  'sort_order' => 4],
                ],
            ],
            [
                'name'       => 'Electrical & LED',
                'sort_order' => 8,
                'children'   => [
                    ['name' => 'LED Modules',        'code' => 'LEM',  'sort_order' => 1],
                    ['name' => 'LED Strips',         'code' => 'LES',  'sort_order' => 2],
                    ['name' => 'Power Supplies',     'code' => 'PSU',  'sort_order' => 3],
                    ['name' => 'Controllers',        'code' => 'CTL',  'sort_order' => 4],
                    ['name' => 'Wiring & Cables',    'code' => 'WRC',  'sort_order' => 5],
                ],
            ],
            [
                'name'       => 'Hardware & Fasteners',
                'sort_order' => 9,
                'children'   => [
                    ['name' => 'Screws & Bolts',     'code' => 'SCR',  'sort_order' => 1],
                    ['name' => 'Hinges & Brackets',  'code' => 'HNG',  'sort_order' => 2],
                    ['name' => 'Standoffs & Fixings','code' => 'STD',  'sort_order' => 3],
                    ['name' => 'Rivets & Clips',     'code' => 'RVT',  'sort_order' => 4],
                ],
            ],
            [
                'name'       => 'Packaging & Dispatch',
                'sort_order' => 10,
                'children'   => [
                    ['name' => 'Stretch Film',       'code' => 'STW',  'sort_order' => 1],
                    ['name' => 'Foam Padding',       'code' => 'FPD',  'sort_order' => 2],
                    ['name' => 'Cardboard Boxes',    'code' => 'CBX',  'sort_order' => 3],
                    ['name' => 'Packing Tape',       'code' => 'PKT',  'sort_order' => 4],
                ],
            ],
            [
                'name'       => 'Cutting Tools',
                'sort_order' => 11,
                'children'   => [
                    ['name' => 'CNC Router Bits',    'code' => 'CRB',  'sort_order' => 1],
                    ['name' => 'Laser Lenses',       'code' => 'LSL',  'sort_order' => 2],
                    ['name' => 'Drill Bits',         'code' => 'DRB',  'sort_order' => 3],
                    ['name' => 'Blades & Knives',    'code' => 'BLD',  'sort_order' => 4],
                ],
            ],
            [
                'name'       => 'Timber & Wood',
                'sort_order' => 12,
                'children'   => [
                    ['name' => 'Solid Timber',       'code' => 'TIM',  'sort_order' => 1],
                    ['name' => 'Treated Timber',     'code' => 'TTM',  'sort_order' => 2],
                    ['name' => 'Dowels & Mouldings', 'code' => 'DWL',  'sort_order' => 3],
                ],
            ],
        ];

        foreach ($taxonomy as $parentData) {
            $children = $parentData['children'] ?? [];
            unset($parentData['children']);

            $parent = MaterialCategory::updateOrCreate(
                ['name' => $parentData['name']],
                array_merge($parentData, ['parent_id' => null, 'is_active' => true])
            );

            foreach ($children as $childData) {
                MaterialCategory::updateOrCreate(
                    ['name' => $childData['name']],
                    array_merge($childData, ['parent_id' => $parent->id, 'is_active' => true])
                );
            }
        }

        if ($this->command) {
            $count = MaterialCategory::count();
            $this->command->info("✅ MaterialCategorySeeder: {$count} categories seeded with codes.");
        }
    }
}
