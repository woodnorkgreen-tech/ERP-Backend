<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

echo "Columns in enquiry_tasks: \n";
print_r(Schema::getColumnListing('enquiry_tasks'));

echo "\nForeign keys:\n";
$result = DB::select("SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'enquiry_tasks' AND REFERENCED_TABLE_NAME IS NOT NULL;");
print_r($result);

echo "\nChecking specifically for project_enquiry_id foreign key:\n";
$result2 = DB::select("SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'enquiry_tasks' AND COLUMN_NAME = 'project_enquiry_id';");
print_r($result2);

if (empty($result2)) {
    echo "\nAdding foreign key for project_enquiry_id...\n";
    Schema::table('enquiry_tasks', function ($table) {
        $table->foreign('project_enquiry_id')->references('id')->on('project_enquiries')->onDelete('cascade');
    });
    echo "Foreign key added.\n";

    // Check again
    $result3 = DB::select("SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'enquiry_tasks' AND COLUMN_NAME = 'project_enquiry_id';");
    print_r($result3);
}
