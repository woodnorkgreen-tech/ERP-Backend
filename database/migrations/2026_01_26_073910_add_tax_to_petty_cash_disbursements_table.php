<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('petty_cash_disbursements', 'tax')) {
            return;
        }

        Schema::table('petty_cash_disbursements', function (Blueprint $table) {
            $table->string('tax')->nullable()->after('job_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('petty_cash_disbursements', 'tax')) {
            return;
        }

        Schema::table('petty_cash_disbursements', function (Blueprint $table) {
            $table->dropColumn('tax');
        });
    }
};
