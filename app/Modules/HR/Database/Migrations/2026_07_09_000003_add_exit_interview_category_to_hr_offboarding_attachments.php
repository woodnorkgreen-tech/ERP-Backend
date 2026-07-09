<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE hr_offboarding_attachments MODIFY category ENUM('asset_return', 'task', 'clearance', 'settlement', 'exit_interview')");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE hr_offboarding_attachments MODIFY category ENUM('asset_return', 'task', 'clearance', 'settlement')");
    }
};
