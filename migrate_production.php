<?php
// Simple script to run production migrations
require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Artisan;

// Run migrations for Production module
Artisan::call('migrate', [
    '--path' => 'app/Modules/Production/Database/Migrations'
]);

echo "Production migrations completed successfully!\n";
