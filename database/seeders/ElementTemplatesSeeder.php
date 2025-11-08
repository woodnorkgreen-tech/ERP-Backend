<?php

namespace Database\Seeders;

use App\Models\ElementTemplate;
use App\Models\ElementTemplateMaterial;
use Illuminate\Database\Seeder;

class ElementTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'stage',
                'display_name' => 'STAGE',
                'description' => 'Main stage structure and components',
                'category' => 'structure',
                'color' => 'green',
                'materials' => [
                    ['description' => 'Stage Boards', 'unit' => 'Pcs', 'quantity' => 8, 'included' => true],
                    ['description' => 'Stage Legs', 'unit' => 'Pcs', 'quantity' => 16, 'included' => true],
                    ['description' => 'Stage Screws', 'unit' => 'Pcs', 'quantity' => 32, 'included' => true],
                    ['description' => 'Stage Brackets', 'unit' => 'Pcs', 'quantity' => 8, 'included' => true],
                ]
            ],
            [
                'name' => 'stage-skirting',
                'display_name' => 'STAGE SKIRTING',
                'description' => 'Stage skirting and decorative elements',
                'category' => 'decoration',
                'color' => 'blue',
                'materials' => [
                    ['description' => 'Skirting Fabric', 'unit' => 'Mtrs', 'quantity' => 12, 'included' => true],
                    ['description' => 'Skirting Clips', 'unit' => 'Pcs', 'quantity' => 24, 'included' => true],
                    ['description' => 'Velcro Strips', 'unit' => 'Mtrs', 'quantity' => 6, 'included' => false],
                ]
            ],
            [
                'name' => 'stage-backdrop',
                'display_name' => 'STAGE BACKDROP',
                'description' => 'Backdrop structure and materials',
                'category' => 'decoration',
                'color' => 'purple',
                'materials' => [
                    ['description' => 'Backdrop Frame', 'unit' => 'Pcs', 'quantity' => 1, 'included' => true],
                    ['description' => 'Backdrop Fabric', 'unit' => 'sqm', 'quantity' => 20, 'included' => true],
                    ['description' => 'Backdrop Weights', 'unit' => 'Pcs', 'quantity' => 4, 'included' => true],
                ]
            ],
            [
                'name' => 'entrance-arc',
                'display_name' => 'ENTRANCE ARC',
                'description' => 'Entrance archway and decorations',
                'category' => 'decoration',
                'color' => 'orange',
                'materials' => [
                    ['description' => 'Arc Frame', 'unit' => 'Pcs', 'quantity' => 1, 'included' => true],
                    ['description' => 'Decorative Flowers', 'unit' => 'Pcs', 'quantity' => 50, 'included' => false],
                    ['description' => 'Arc Fabric Draping', 'unit' => 'Mtrs', 'quantity' => 8, 'included' => true],
                ]
            ],
            [
                'name' => 'walkway-dance-floor',
                'display_name' => 'WALKWAY AND DANCE FLOOR',
                'description' => 'Walkway and dance floor components',
                'category' => 'flooring',
                'color' => 'teal',
                'materials' => [
                    ['description' => 'Dance Floor Panels', 'unit' => 'sqm', 'quantity' => 36, 'included' => true],
                    ['description' => 'Walkway Carpet', 'unit' => 'Mtrs', 'quantity' => 15, 'included' => true],
                    ['description' => 'Floor Marking Tape', 'unit' => 'Mtrs', 'quantity' => 20, 'included' => false],
                ]
            ],
        ];

        foreach ($templates as $index => $templateData) {
            $template = ElementTemplate::create([
                'name' => $templateData['name'],
                'display_name' => $templateData['display_name'],
                'description' => $templateData['description'],
                'category' => $templateData['category'],
                'color' => $templateData['color'],
                'sort_order' => $index + 1,
            ]);

            foreach ($templateData['materials'] as $materialIndex => $materialData) {
                ElementTemplateMaterial::create([
                    'element_template_id' => $template->id,
                    'description' => $materialData['description'],
                    'unit_of_measurement' => $materialData['unit'],
                    'default_quantity' => $materialData['quantity'],
                    'is_default_included' => $materialData['included'],
                    'sort_order' => $materialIndex + 1,
                ]);
            }
        }
    }
}
