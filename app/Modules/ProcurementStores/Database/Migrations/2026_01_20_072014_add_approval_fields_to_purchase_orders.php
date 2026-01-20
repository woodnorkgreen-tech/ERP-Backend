<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });
        // Change status field to VARCHAR
        DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'");
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['submitted_at', 'approved_at', 'approved_by']);
        });
         DB::statement("ALTER TABLE purchase_orders MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'");
    }
};