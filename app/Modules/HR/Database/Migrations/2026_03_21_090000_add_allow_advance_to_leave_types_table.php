<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('allow_advance')->default(false)->after('monthly_accrual_rate');
        });

        DB::table('leave_types')->where('code', 'ANNUAL')->update([
            'allow_advance' => true,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('allow_advance');
        });
    }
};
