<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('goods_receipt_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_note_item_id')->unique()->constrained('goods_receipt_note_items')->cascadeOnDelete();
            $table->decimal('inspected_quantity', 18, 6);
            $table->decimal('accepted_quantity', 18, 6)->default(0);
            $table->decimal('rejected_quantity', 18, 6)->default(0);
            $table->decimal('quarantined_quantity', 18, 6)->default(0);
            $table->string('outcome', 40);
            $table->string('status', 30)->default('resolved');
            $table->text('findings');
            $table->text('condition_notes')->nullable();
            $table->string('supplier_action', 40)->nullable();
            $table->date('supplier_action_due_on')->nullable();
            $table->string('supplier_reference', 120)->nullable();
            $table->foreignId('inspected_by')->constrained('users');
            $table->timestamp('inspected_at');
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('goods_receipt_inspections'); }
};
