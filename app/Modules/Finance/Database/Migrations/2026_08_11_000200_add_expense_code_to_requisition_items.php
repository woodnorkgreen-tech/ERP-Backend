<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->foreignId('expense_code_id')->nullable()->after('material_id')
                ->constrained('expense_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('requisition_items', fn (Blueprint $table) =>
            $table->dropConstrainedForeignId('expense_code_id'));
    }
};
