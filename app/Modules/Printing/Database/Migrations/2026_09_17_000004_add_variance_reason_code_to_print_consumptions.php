<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_job_consumptions', function (Blueprint $table) {
            $table->string('variance_reason_code', 60)->nullable()->after('variance_percent')->index();
        });

        DB::table('print_job_consumptions')
            ->whereNotNull('variance_reason')
            ->where('variance_reason', '<>', '')
            ->update(['variance_reason_code' => 'other']);
    }

    public function down(): void
    {
        Schema::table('print_job_consumptions', function (Blueprint $table) {
            $table->dropColumn('variance_reason_code');
        });
    }
};
