<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "=== Checking Petty Cash Requisitions Schema ===\n\n";

// Check if tables exist
$tables = ['petty_cash_requisitions', 'petty_cash_requisition_items'];
foreach ($tables as $table) {
    if (Schema::hasTable($table)) {
        echo "✅ Table '$table' exists\n";
        
        // Get columns
        $columns = Schema::getColumnListing($table);
        echo "   Columns: " . implode(', ', $columns) . "\n\n";
    } else {
        echo "❌ Table '$table' DOES NOT exist\n\n";
    }
}

// Check specific required columns
echo "=== Checking Required Columns ===\n\n";

$requiredColumns = [
    'petty_cash_requisitions' => [
        'id', 'user_id', 'department_id', 'category', 'purpose', 
        'project_id', 'enquiry_id', 'payee_id', 'payee_name',
        'requisition_number', 'total_amount', 'status', 'created_at', 'updated_at'
    ],
    'petty_cash_requisition_items' => [
        'id', 'requisition_id', 'description', 'amount',
        'payee_id', 'payee_name', 'created_at', 'updated_at'
    ]
];

foreach ($requiredColumns as $table => $columns) {
    echo "Table: $table\n";
    foreach ($columns as $column) {
        if (Schema::hasColumn($table, $column)) {
            echo "  ✅ $column\n";
        } else {
            echo "  ❌ $column (MISSING)\n";
        }
    }
    echo "\n";
}

// Check foreign keys
echo "=== Checking Foreign Key References ===\n\n";

$fkChecks = [
    ['table' => 'departments', 'id' => 1],
    ['table' => 'users', 'id' => 1],
    ['table' => 'employees', 'id' => 1],
];

foreach ($fkChecks as $check) {
    $exists = DB::table($check['table'])->where('id', $check['id'])->exists();
    $status = $exists ? '✅' : '❌';
    echo "$status {$check['table']} (ID {$check['id']}): " . ($exists ? 'EXISTS' : 'NOT FOUND') . "\n";
}

echo "\n=== Schema Check Complete ===\n";
