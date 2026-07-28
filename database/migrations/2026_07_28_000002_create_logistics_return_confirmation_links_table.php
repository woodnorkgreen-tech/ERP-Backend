<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_return_confirmation_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_task_id')->constrained('logistics_tasks')->cascadeOnDelete();
            $table->uuid('token')->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['logistics_task_id', 'revoked_at'], 'return_confirm_task_revoked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_return_confirmation_links');
    }
};
