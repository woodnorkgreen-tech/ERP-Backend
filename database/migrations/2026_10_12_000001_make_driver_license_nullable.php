<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Drivers are now pulled from the HR employee list, which has no licence
// details, so the licence fields can be filled in afterwards.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE drivers MODIFY license_number VARCHAR(255) NULL');
        DB::statement('ALTER TABLE drivers MODIFY license_expiry DATE NULL');
    }

    public function down(): void
    {
        // Left nullable: rows created from HR may have no licence yet.
    }
};
