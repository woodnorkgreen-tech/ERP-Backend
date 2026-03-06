<?php

namespace App\Modules\Finance\Database\Seeders;

use App\Modules\Finance\Models\ChartOfAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class ChartOfAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $jsonPath = base_path('../frontend/src/data/accounts.json');

        if (!File::exists($jsonPath)) {
            $this->command->error("Source file not found at: {$jsonPath}");
            return;
        }

        $json = File::get($jsonPath);
        $data = json_decode($json, true);

        if (isset($data['accounts'])) {
            foreach ($data['accounts'] as $account) {
                ChartOfAccount::updateOrCreate(
                    ['code' => $account['code']],
                    [
                        'name' => $account['name'],
                        'category' => $account['category'],
                        'is_active' => true,
                    ]
                );
            }
            $this->command->info('Chart of accounts seeded successfully.');
        }
    }
}
