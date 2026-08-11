<?php

namespace App\Modules\Design\Database\Seeders;

use App\Modules\Design\Models\DesignType;
use Illuminate\Database\Seeder;

class DesignTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            'graphic' => [
                'Sticker' => 'Cut or printed adhesive graphics applied to surfaces, vehicles, or products.',
                'Banner' => 'Large-format printed graphic, typically vinyl or fabric, for indoor or outdoor display.',
                'Backdrop' => 'Printed background graphic for stages, booths, or photo areas.',
                'Flag' => 'Printed fabric flag or feather banner for outdoor branding and signage.',
                'Poster' => 'Printed promotional graphic for display boards or standees.',
                'Fabric Print' => 'Printed fabric material used for tension structures, drapes, or soft signage.',
            ],
            'structural' => [
                'Booth' => 'Exhibition or event booth structure, including frame and fit-out.',
                'Counter' => 'Reception or service counter structure for booths and activations.',
                'Display Stand' => 'Freestanding structure for showcasing products or promotional material.',
                'Arch' => 'Entrance or decorative arch structure, often paired with branding graphics.',
                'Signage Structure' => 'Freestanding or mounted structural frame built to carry signage.',
                'Custom Structure' => 'One-off or bespoke structural build not covered by a standard type.',
            ],
        ];

        foreach ($types as $stream => $names) {
            $index = 0;
            foreach ($names as $name => $description) {
                $index++;
                DesignType::updateOrCreate(
                    ['stream' => $stream, 'name' => $name],
                    ['description' => $description, 'is_active' => true, 'sort_order' => $index]
                );
            }
        }
    }
}
