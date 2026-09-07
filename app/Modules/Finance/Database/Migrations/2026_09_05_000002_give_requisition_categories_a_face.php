<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives each requisition category its own icon and a line saying what it covers.
 *
 * The categories introduced by the previous migration took the table's column
 * default for `icon` and left `description` null. The picker draws a tile per
 * category from exactly those two columns and falls back to the words "Fund
 * request" when there is no description — so twenty-two tiles arrived carrying
 * the same coin icon and the same subtitle. The eight they replaced each had a
 * distinct icon and a real sentence, which is what made a wall of tiles
 * scannable at all. This restores that, at more than twice the count.
 *
 * The two office categories say so in their own description. They cannot be
 * charged to a job — their expense codes forbid it and the commitment producer
 * skips them — and the cheapest place to say that is where the choice is made.
 */
return new class extends Migration
{
    /** category code => [icon, what it covers]. */
    private const FACES = [
        'tl_crw_001' => ['mdi-account-group-outline', 'Getting the crew to and from site.'],
        'tl_fue_001' => ['mdi-gas-station-outline', 'Fuel for a vehicle running a job.'],
        'tl_hir_001' => ['mdi-truck-outline', 'Hiring a truck or vehicle for a job.'],
        'tl_cur_001' => ['mdi-package-variant-closed', 'Sending documents or items by courier.'],
        'tl_hnd_001' => ['mdi-forklift', 'Paying hands to load, offload or carry.'],

        'pf_mea_001' => ['mdi-food-outline', 'Feeding the crew during a job.'],
        'pf_acc_001' => ['mdi-bed-outline', 'Overnight stay for crew away from base.'],
        'pf_pdm_001' => ['mdi-wallet-travel', 'Daily allowance for crew working away.'],
        'dl_cas_001' => ['mdi-account-hard-hat-outline', 'Paying casuals engaged on site.'],
        'dl_cas_002' => ['mdi-hammer-wrench', 'Paying casuals engaged in the workshop.'],
        'dl_alw_001' => ['mdi-clock-plus-outline', 'Allowance or overtime earned on a job.'],

        'pu_fue_001' => ['mdi-fuel', 'Fuel to run a generator on site.'],
        'pu_pwr_001' => ['mdi-flash-outline', 'Electricity or power supply for a site.'],
        'pu_wtr_001' => ['mdi-water-outline', 'Water delivery or sanitation on site.'],
        'pu_wst_001' => ['mdi-delete-outline', 'Clearing waste from a site.'],
        'eq_saf_001' => ['mdi-shield-account-outline', 'Protective equipment for the crew.'],
        'eq_tol_001' => ['mdi-toolbox-outline', 'Small tools and consumables for a job.'],
        'vs_prm_001' => ['mdi-file-certificate-outline', 'Permits or licences a job requires.'],
        'vs_sec_001' => ['mdi-shield-home-outline', 'Guarding a site or stored materials.'],

        'oe_com_001' => ['mdi-phone-outline', 'Airtime or data. Office cost, never charged to a job.'],
        'oe_trp_001' => ['mdi-car-outline', 'Local travel on office business, not for a job.'],
        'oe_wel_001' => ['mdi-coffee-outline', 'Staff welfare. Office cost, never charged to a job.'],
    ];

    public function up(): void
    {
        foreach (self::FACES as $code => [$icon, $description]) {
            DB::table('petty_cash_requisition_types')
                ->where('code', $code)
                // Never overwrite what an administrator has since written: this
                // fills a blank, it does not impose a house style on their words.
                ->where(function ($query) {
                    $query->whereNull('description')->orWhere('description', '');
                })
                ->update([
                    'icon' => $icon,
                    'description' => $description,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        DB::table('petty_cash_requisition_types')
            ->whereIn('code', array_keys(self::FACES))
            ->update(['icon' => 'mdi-cash-multiple', 'description' => null, 'updated_at' => now()]);
    }
};
