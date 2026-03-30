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
        Schema::table('employees', function (Blueprint $table) {
            // Identity Nodes
            $table->string('id_number')->nullable()->after('last_name')->index();
            $table->string('kra_pin')->nullable()->after('id_number')->comment('Kenya Revenue Authority PIN');
            $table->string('nssf_id')->nullable()->after('kra_pin')->comment('Social Security Number');
            $table->string('nhif_id')->nullable()->after('nssf_id')->comment('Health Insurance Number');

            // Financial Matrix
            $table->string('bank_name')->nullable()->after('salary');
            $table->string('bank_branch')->nullable()->after('bank_name');
            $table->string('account_number')->nullable()->after('bank_branch');
            $table->string('payment_method')->default('bank')->after('account_number');

            // Compliance & Timeline
            $table->date('probation_end_date')->nullable()->after('hire_date');
            $table->boolean('is_on_probation')->default(true)->after('probation_end_date');
            $table->date('contract_end_date')->nullable()->after('is_on_probation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'id_number',
                'kra_pin',
                'nssf_id',
                'nhif_id',
                'bank_name',
                'bank_branch',
                'account_number',
                'payment_method',
                'probation_end_date',
                'is_on_probation',
                'contract_end_date'
            ]);
        });
    }
};
