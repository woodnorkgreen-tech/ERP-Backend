<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_mid_qc_checks', function (Blueprint $table) {
            $table->string('qc_stage', 50)->nullable()->after('workstation');
        });

        DB::table('work_order_mid_qc_checks')
            ->whereNull('qc_stage')
            ->update(['qc_stage' => 'post_fabrication']);
    }

    public function down(): void
    {
        Schema::table('work_order_mid_qc_checks', function (Blueprint $table) {
            $table->dropColumn('qc_stage');
        });
    }
};
