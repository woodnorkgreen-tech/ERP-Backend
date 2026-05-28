<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE deliveries MODIFY COLUMN status ENUM('pending','in_transit','partial','delivered','failed','cancelled') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE deliveries MODIFY COLUMN status ENUM('pending','in_transit','partial','delivered','failed') NOT NULL DEFAULT 'pending'");
    }
};
