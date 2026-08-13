<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiry_payments', function (Blueprint $table) {
            $table->foreignId('payment_source_id')->nullable()->after('payment_method')
                ->constrained('payment_sources')->nullOnDelete();
            $table->string('evidence_path')->nullable()->after('transaction_reference');
            $table->index(['payment_source_id', 'transaction_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('enquiry_payments', function (Blueprint $table) {
            $table->dropIndex(['payment_source_id', 'transaction_reference']);
            $table->dropConstrainedForeignId('payment_source_id');
            $table->dropColumn('evidence_path');
        });
    }
};
