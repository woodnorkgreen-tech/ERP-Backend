<?php
 
namespace App\Modules\HR\Database\Seeders;
 
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Modules\HR\Models\PayrollVariable;
use App\Modules\HR\Models\PayrollTaxBand;
 
class PayrollSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Initial Payroll Variables (March 2026 Kenyan Requirements)
        $variables = [
            ['name' => 'NSSF_RATE', 'type' => 'percentage', 'value' => 0.06, 'description' => 'Social Security Rate (6%)'],
            ['name' => 'NSSF_TIER_I_LIMIT', 'type' => 'fixed_amount', 'value' => 9000.00, 'description' => 'NSSF Tier I Lower Limit'],
            ['name' => 'NSSF_TIER_II_LIMIT', 'type' => 'fixed_amount', 'value' => 108000.00, 'description' => 'NSSF Tier II Upper Limit'],
            ['name' => 'SHIF_RATE', 'type' => 'percentage', 'value' => 0.0275, 'description' => 'Health Insurance Rate (2.75%)'],
            ['name' => 'SHIF_MIN', 'type' => 'fixed_amount', 'value' => 300.00, 'description' => 'Minimum SHIF Contribution'],
            ['name' => 'HOUSING_LEVY_RATE', 'type' => 'percentage', 'value' => 0.015, 'description' => 'Affordable Housing Levy Rate (1.5%)'],
            ['name' => 'PERSONAL_RELIEF', 'type' => 'fixed_amount', 'value' => 2400.00, 'description' => 'Monthly Personal Tax Relief'],
        ];
 
        foreach ($variables as $var) {
            PayrollVariable::updateOrCreate(['name' => $var['name']], $var);
        }
 
        // 2. Initial PAYE Tax Bands (Kenya 2024)
        $bands = [
            ['name' => 'Band 1', 'min_amount' => 0, 'max_amount' => 24000, 'rate' => 0.10, 'sort_order' => 1],
            ['name' => 'Band 2', 'min_amount' => 24000, 'max_amount' => 32333, 'rate' => 0.25, 'sort_order' => 2],
            ['name' => 'Band 3', 'min_amount' => 32333, 'max_amount' => 500000, 'rate' => 0.30, 'sort_order' => 3],
            ['name' => 'Band 4', 'min_amount' => 500000, 'max_amount' => 800000, 'rate' => 0.325, 'sort_order' => 4],
            ['name' => 'Band 5', 'min_amount' => 800000, 'max_amount' => null, 'rate' => 0.35, 'sort_order' => 5],
        ];
 
        foreach ($bands as $band) {
            PayrollTaxBand::updateOrCreate(['name' => $band['name']], $band);
        }
    }
}
