<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('leave_types')
            ->whereIn('code', ['MATERNITY', 'PATERNITY'])
            ->update([
                'requires_attachment' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('leave_types')
            ->whereIn('code', ['MATERNITY', 'PATERNITY'])
            ->update([
                'requires_attachment' => true,
                'updated_at' => now(),
            ]);
    }
};
