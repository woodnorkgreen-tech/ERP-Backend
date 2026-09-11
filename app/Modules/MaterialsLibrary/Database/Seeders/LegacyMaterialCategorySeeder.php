<?php

namespace App\Modules\MaterialsLibrary\Database\Seeders;

use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use Illuminate\Database\Seeder;

/**
 * Additive seeder providing the 24 legacy development groups and their child categories.
 *
 * Uses firstOrCreate(['name', 'parent_id']) so existing custom categories,
 * materials, and controls are preserved without modification.
 */
class LegacyMaterialCategorySeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            [
                'name' => 'MDF',
                'code' => 'MDF2',
                'sort_order' => 13,
                'children' => [
                    ['name' => 'Sheet', 'code' => 'SHEE', 'sort_order' => 1],
                    ['name' => 'Moisture Resistant', 'code' => 'MR', 'sort_order' => 2],
                ],
            ],
            [
                'name' => 'PVC Foam Board',
                'code' => 'PFB',
                'sort_order' => 14,
                'children' => [
                    ['name' => 'PVC', 'code' => 'PVC2', 'sort_order' => 1],
                ],
            ],
            [
                'name' => 'Acrylic',
                'code' => 'ACRY',
                'sort_order' => 15,
                'children' => [
                    ['name' => 'Clear', 'code' => 'CLEA', 'sort_order' => 1],
                    ['name' => 'Colored', 'code' => 'COLO', 'sort_order' => 2],
                ],
            ],
            [
                'name' => 'HDPE',
                'code' => 'HDPE',
                'sort_order' => 16,
                'children' => [
                    ['name' => 'Sheet', 'code' => 'SHEE2', 'sort_order' => 1],
                ],
            ],
            [
                'name' => 'ACP',
                'code' => 'ACP2',
                'sort_order' => 17,
                'children' => [
                    ['name' => 'Composite Panel', 'code' => 'CP', 'sort_order' => 1],
                ],
            ],
            [
                'name' => 'CNC Bit',
                'code' => 'CB',
                'sort_order' => 18,
                'children' => [
                    ['name' => 'Cutting Tool', 'code' => 'CT', 'sort_order' => 1],
                ],
            ],
            [
                'name' => 'CNC Accessory',
                'code' => 'CA',
                'sort_order' => 19,
                'children' => [
                    ['name' => 'Clamps', 'code' => 'CLAM', 'sort_order' => 1],
                    ['name' => 'Tape', 'code' => 'TAPE', 'sort_order' => 2],
                    ['name' => 'Spoil Board', 'code' => 'SB', 'sort_order' => 3],
                    ['name' => 'Collet', 'code' => 'COLL', 'sort_order' => 4],
                    ['name' => 'Attachment', 'code' => 'ATTA', 'sort_order' => 5],
                ],
            ],
            [
                'name' => 'Plastics',
                'code' => 'PLAS',
                'sort_order' => 20,
                'children' => [
                    ['name' => 'Acrylic', 'code' => 'ACRY2', 'sort_order' => 1],
                ],
            ],
            [
                'name' => 'Wood',
                'code' => 'WOOD',
                'sort_order' => 21,
                'children' => [
                    ['name' => 'MDF', 'code' => 'MDF3', 'sort_order' => 1],
                    ['name' => 'Timber', 'code' => 'TIMB', 'sort_order' => 2],
                ],
            ],
            [
                'name' => 'Finishing',
                'code' => 'FINI',
                'sort_order' => 22,
                'children' => [
                    ['name' => 'Tapes', 'code' => 'TAPE2', 'sort_order' => 1],
                ],
            ],
            [
                'name' => 'Chemicals',
                'code' => 'CHEM',
                'sort_order' => 23,
                'children' => [
                    ['name' => 'Cleaners', 'code' => 'CLEA2', 'sort_order' => 1],
                    ['name' => 'Thinners', 'code' => 'THIN', 'sort_order' => 2],
                    ['name' => 'Treatments', 'code' => 'TREA', 'sort_order' => 3],
                    ['name' => 'Hardener', 'code' => 'HARD2', 'sort_order' => 4],
                ],
            ],
            [
                'name' => 'Printable Vinyl',
                'code' => 'PV',
                'sort_order' => 24,
                'children' => [
                    ['name' => 'Frontlit', 'code' => 'FRON', 'sort_order' => 1],
                    ['name' => 'Backlit', 'code' => 'BACK', 'sort_order' => 2],
                    ['name' => 'Blockout', 'code' => 'BLOC', 'sort_order' => 3],
                ],
            ],
            [
                'name' => 'Self-Adhesive Vinyl',
                'code' => 'SAV',
                'sort_order' => 25,
                'children' => [
                    ['name' => 'Monomeric', 'code' => 'MONO', 'sort_order' => 1],
                    ['name' => 'Polymeric', 'code' => 'POLY', 'sort_order' => 2],
                    ['name' => 'Cast Vinyl', 'code' => 'CV', 'sort_order' => 3],
                ],
            ],
            [
                'name' => 'Specialty Vinyl',
                'code' => 'SV',
                'sort_order' => 26,
                'children' => [
                    ['name' => 'Frosted', 'code' => 'FROS', 'sort_order' => 1],
                    ['name' => 'Reflective', 'code' => 'REFL', 'sort_order' => 2],
                    ['name' => 'Metallic', 'code' => 'META', 'sort_order' => 3],
                    ['name' => 'Textured', 'code' => 'TEXT', 'sort_order' => 4],
                    ['name' => 'Glow/Fluorescent', 'code' => 'GF', 'sort_order' => 5],
                ],
            ],
            [
                'name' => 'Floor Vinyl',
                'code' => 'FV',
                'sort_order' => 27,
                'children' => [
                    ['name' => 'Anti-Slip', 'code' => 'AS', 'sort_order' => 1],
                ],
            ],
            [
                'name' => 'Ink',
                'code' => 'INK',
                'sort_order' => 28,
                'children' => [
                    ['name' => 'UV', 'code' => 'UV', 'sort_order' => 1],
                ],
            ],
            [
                'name' => 'Substrate',
                'code' => 'SUBS',
                'sort_order' => 29,
                'children' => [
                    ['name' => 'ACP', 'code' => 'ACP3', 'sort_order' => 1],
                    ['name' => 'PVC Foam Board', 'code' => 'PFB2', 'sort_order' => 2],
                    ['name' => 'Glass', 'code' => 'GLAS', 'sort_order' => 3],
                ],
            ],
            [
                'name' => 'Metals',
                'code' => 'META2',
                'sort_order' => 30,
                'children' => [
                    ['name' => 'Square Tube', 'code' => 'ST', 'sort_order' => 1],
                    ['name' => 'Round Tube', 'code' => 'RT', 'sort_order' => 2],
                    ['name' => 'Flat Bar', 'code' => 'FB', 'sort_order' => 3],
                ],
            ],
            [
                'name' => 'Consumables',
                'code' => 'CONS',
                'sort_order' => 31,
                'children' => [
                    ['name' => 'Welding', 'code' => 'WELD', 'sort_order' => 1],
                    ['name' => 'Gas', 'code' => 'GAS', 'sort_order' => 2],
                    ['name' => 'Grinding', 'code' => 'GRIN', 'sort_order' => 3],
                    ['name' => 'Adhesives', 'code' => 'ADHE', 'sort_order' => 4],
                    ['name' => 'Fillers', 'code' => 'FILL', 'sort_order' => 5],
                    ['name' => 'Tapes', 'code' => 'TAPE3', 'sort_order' => 6],
                    ['name' => 'Abrasives', 'code' => 'ABRA', 'sort_order' => 7],
                    ['name' => 'Application Tools', 'code' => 'AT', 'sort_order' => 8],
                    ['name' => 'Cleaning', 'code' => 'CLEA4', 'sort_order' => 9],
                    ['name' => 'Sealants', 'code' => 'SEAL', 'sort_order' => 10],
                    ['name' => 'PPE & Support', 'code' => 'PS', 'sort_order' => 11],
                    ['name' => 'Sealant', 'code' => 'SEAL2', 'sort_order' => 12],
                    ['name' => 'Sealant & Insulation', 'code' => 'SI', 'sort_order' => 13],
                ],
            ],
            [
                'name' => 'Paint',
                'code' => 'PAIN',
                'sort_order' => 32,
                'children' => [
                    ['name' => 'Primer', 'code' => 'PRIM', 'sort_order' => 1],
                    ['name' => 'Top Coat', 'code' => 'TC', 'sort_order' => 2],
                    ['name' => 'Clear', 'code' => 'CLEA3', 'sort_order' => 3],
                    ['name' => 'Wall Paint', 'code' => 'WP', 'sort_order' => 4],
                    ['name' => 'Special Coating', 'code' => 'SC', 'sort_order' => 5],
                    ['name' => 'Spray', 'code' => 'SPRA', 'sort_order' => 6],
                ],
            ],
            [
                'name' => 'Hardware',
                'code' => 'HARD',
                'sort_order' => 33,
                'children' => [
                    ['name' => 'Screws', 'code' => 'SCRE', 'sort_order' => 1],
                    ['name' => 'Connectors', 'code' => 'CONN', 'sort_order' => 2],
                    ['name' => 'Installation', 'code' => 'INST', 'sort_order' => 3],
                    ['name' => 'Bolts', 'code' => 'BOLT', 'sort_order' => 4],
                    ['name' => 'Plugs', 'code' => 'PLUG', 'sort_order' => 5],
                    ['name' => 'Washers', 'code' => 'WASH', 'sort_order' => 6],
                    ['name' => 'Anchors', 'code' => 'ANCH', 'sort_order' => 7],
                    ['name' => 'Brackets', 'code' => 'BRAC', 'sort_order' => 8],
                    ['name' => 'Hinges', 'code' => 'HING', 'sort_order' => 9],
                    ['name' => 'Rivets', 'code' => 'RIVE', 'sort_order' => 10],
                    ['name' => 'Clamps', 'code' => 'CLAM2', 'sort_order' => 11],
                    ['name' => 'Abrasives', 'code' => 'ABRA2', 'sort_order' => 12],
                    ['name' => 'Blades', 'code' => 'BLAD', 'sort_order' => 13],
                    ['name' => 'Measuring & Marking', 'code' => 'MM', 'sort_order' => 14],
                    ['name' => 'Workshop Accessories', 'code' => 'WA', 'sort_order' => 15],
                    ['name' => 'Installation Aids', 'code' => 'IA', 'sort_order' => 16],
                    ['name' => 'PPE', 'code' => 'PPE', 'sort_order' => 17],
                ],
            ],
            [
                'name' => 'Electronics',
                'code' => 'ELEC',
                'sort_order' => 34,
                'children' => [
                    ['name' => 'LED Strip', 'code' => 'LS', 'sort_order' => 1],
                    ['name' => 'Power Supply', 'code' => 'PS2', 'sort_order' => 2],
                    ['name' => 'Cable', 'code' => 'CABL', 'sort_order' => 3],
                    ['name' => 'Controller', 'code' => 'CONT', 'sort_order' => 4],
                    ['name' => 'Protection & Control', 'code' => 'PC', 'sort_order' => 5],
                ],
            ],
            [
                'name' => 'Packaging',
                'code' => 'PACK',
                'sort_order' => 35,
                'children' => [
                    ['name' => 'Tape', 'code' => 'TAPE4', 'sort_order' => 1],
                    ['name' => 'Protective', 'code' => 'PROT', 'sort_order' => 2],
                    ['name' => 'Cartons', 'code' => 'CART', 'sort_order' => 3],
                    ['name' => 'Wrapping', 'code' => 'WRAP', 'sort_order' => 4],
                    ['name' => 'Labels', 'code' => 'LABE', 'sort_order' => 5],
                ],
            ],
            [
                'name' => 'Hardware (Workshop Accessories)',
                'code' => 'HWA',
                'sort_order' => 36,
                'children' => [
                    ['name' => 'Hardware (Workshop Accessories)', 'code' => 'HWA2', 'sort_order' => 1],
                ],
            ],
        ];

        foreach ($groups as $group) {
            $children = $group['children'] ?? [];
            unset($group['children']);

            $parent = MaterialCategory::firstOrCreate(
                ['name' => $group['name'], 'parent_id' => null],
                array_merge($group, ['parent_id' => null, 'is_active' => true, 'is_selectable' => false]),
            );

            foreach ($children as $child) {
                MaterialCategory::firstOrCreate(
                    ['name' => $child['name'], 'parent_id' => $parent->id],
                    array_merge($child, ['parent_id' => $parent->id, 'is_active' => true, 'is_selectable' => true]),
                );
            }
        }

        if ($this->command) {
            $count = MaterialCategory::count();
            $this->command?->info("LegacyMaterialCategorySeeder: {$count} categories seeded.");
        }
    }
}
