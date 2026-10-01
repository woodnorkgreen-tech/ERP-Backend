<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_logs MODIFY COLUMN type ENUM('check_in','check_out','return','adjustment','defective','allocated','reversal') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_logs MODIFY COLUMN type ENUM('check_in','check_out','return','adjustment','defective','allocated') NOT NULL");
        }
    }
};
