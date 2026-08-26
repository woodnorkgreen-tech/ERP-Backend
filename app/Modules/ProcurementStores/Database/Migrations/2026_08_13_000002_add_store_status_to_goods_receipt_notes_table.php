<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            // pending_confirmation until every accepted item on this GRN has
            // been confirmed by Stores; confirmed once they all are.
            $table->string('store_status')->default('pending_confirmation')->after('quality_check');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->dropColumn('store_status');
        });
    }
};
