<?php

namespace Database\Seeders;

use App\Modules\Teams\Models\TeamCategory;
use App\Modules\Teams\Models\TeamCategoryType;
use App\Modules\Teams\Models\TeamType;
use Illuminate\Database\Seeder;

/**
 * Which team types each category may draw on, and how many people in each.
 *
 * Resolved through `category_key` and `type_key`, which is how the two parent
 * seeders identify their own rows. This file used to name surrogate ids — 27
 * rows of `['category_id' => 1, 'team_type_id' => 4, …]` with a comment saying
 * which was which — so it was correct only on a database seeded exactly once
 * from empty, where the auto-increments happened to land on 1..3 and 1..9.
 *
 * Anywhere else it was wrong in one of two ways: the foreign key failed, or
 * worse, it succeeded against whatever rows those ids now pointed at and
 * quietly gave the Setup category the Welders' crew sizes. The ids also drift
 * under `RefreshDatabase`, because MySQL does not roll an auto-increment
 * counter back with the transaction — which is how this surfaced.
 */
class TeamCategoryTypesSeeder extends Seeder
{
    /** The three categories, by the key TeamCategoriesSeeder gives them. */
    private const CATEGORIES = ['workshop', 'setup', 'setdown'];

    /**
     * type_key => maximum crew size.
     *
     * One number per type, not per pairing: all three categories carried the
     * same maxima in the list this replaces, and a minimum of one throughout.
     */
    private const MAX_MEMBERS = [
        'pasting_team' => 10,
        'technicians' => 8,
        'painters' => 6,
        'welders' => 4,
        'electricians' => 5,
        'ict' => 3,
        'loading' => 12,
        'offloading' => 12,
        'carpenters' => 7,
    ];

    public function run(): void
    {
        $categories = TeamCategory::pluck('id', 'category_key');
        $types = TeamType::pluck('id', 'type_key');

        foreach (self::CATEGORIES as $categoryKey) {
            if (! $categories->has($categoryKey)) {
                $this->command?->warn("Team category '{$categoryKey}' is missing; its types are skipped.");

                continue;
            }

            foreach (self::MAX_MEMBERS as $typeKey => $maxMembers) {
                if (! $types->has($typeKey)) {
                    $this->command?->warn("Team type '{$typeKey}' is missing; skipped.");

                    continue;
                }

                TeamCategoryType::updateOrCreate(
                    [
                        'category_id' => $categories[$categoryKey],
                        'team_type_id' => $types[$typeKey],
                    ],
                    [
                        'is_available' => true,
                        'required' => false,
                        'min_members' => 1,
                        'max_members' => $maxMembers,
                    ],
                );
            }
        }
    }
}
