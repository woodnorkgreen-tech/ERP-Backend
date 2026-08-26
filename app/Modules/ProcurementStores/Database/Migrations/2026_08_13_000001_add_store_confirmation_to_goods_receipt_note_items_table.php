<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_note_items', function (Blueprint $table) {
            // pending = arrived at the dock, waiting for Stores to match/create
            // the material and price it in. confirmed = it's in Stock now.
            $table->string('store_status')->default('pending')->after('accepted');
            $table->decimal('unit_price', 12, 2)->nullable()->after('store_status');
            $table->foreignId('confirmed_by')->nullable()->after('unit_price')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_note_items', function (Blueprint $table) {
            $table->dropForeign(['confirmed_by']);
            $table->dropColumn(['store_status', 'unit_price', 'confirmed_by', 'confirmed_at']);
        });
    }
};
