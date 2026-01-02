<?php

namespace App\Modules\MaterialsLibrary\Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\MaterialsLibrary\Models\Workstation;

class WorkstationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $workstations = [
            [
                'code' => 'CNC',
                'name' => 'CNC Router Workstation',
                'description' => 'All sheet cutting for MDF, plywood, PVC foam, acrylic (routing only).',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'code' => 'LASER',
                'name' => 'Laser Cutter Workstation',
                'description' => 'Acrylic and light material laser cutting and engraving.',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'LFP',
                'name' => 'Large Format Print Workstation',
                'description' => 'Roll-to-roll printing for banners, vinyl, mesh, backdrops.',
                'sort_order' => 3,
                'is_active' => true,
            ],
            [
                'code' => 'UV',
                'name' => 'UV Flatbed Print Workstation',
                'description' => 'Flatbed printing on rigid substrates: MDF, ACP, acrylic, PVC board.',
                'sort_order' => 4,
                'is_active' => true,
            ],
            [
                'code' => 'MET',
                'name' => 'Metal Fabrication & Welding',
                'description' => 'Steel/aluminium cutting, welding, grinding, frame fabrication.',
                'sort_order' => 5,
                'is_active' => true,
            ],
            [
                'code' => 'CARP',
                'name' => 'Carpentry & Woodwork',
                'description' => 'Timber/MDF fabrication, framing, counters, cabinetry.',
                'sort_order' => 6,
                'is_active' => true,
            ],
            [
                'code' => 'PAINT',
                'name' => 'Paint & Finishing Booth',
                'description' => 'Surface prep, priming, spraying, clear-coating.',
                'sort_order' => 7,
                'is_active' => true,
            ],
            [
                'code' => 'LED',
                'name' => 'Electrical & LED Signage',
                'description' => 'LED modules, power supplies, wiring for signage/lightboxes.',
                'sort_order' => 8,
                'is_active' => true,
            ],
            [
                'code' => 'GEN',
                'name' => 'General Hardware & Packaging',
                'description' => 'Cross-cutting consumables: screws, fasteners, tapes, packaging.',
                'sort_order' => 9,
                'is_active' => true,
            ],
        ];

        foreach ($workstations as $workstation) {
            Workstation::updateOrCreate(
                ['code' => $workstation['code']],
                $workstation
            );
        }

        $this->command->info('✅ Workstations seeded successfully!');
    }
}
