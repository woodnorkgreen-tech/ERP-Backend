<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_handoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_item_id')->constrained('design_items')->cascadeOnDelete();
            $table->enum('target_module', ['printing', 'production', 'materials']);
            $table->unsignedBigInteger('target_record_id')->nullable();
            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled'])->default('pending');
            $table->json('payload_snapshot')->nullable();
            $table->foreignId('handed_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handed_off_at')->nullable();
            $table->timestamps();

            $table->index(['design_item_id', 'target_module']);
            $table->index(['target_module', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_handoffs');
    }
};
