<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_period_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_period_id')->constrained('accounting_periods')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('from_status');
            $table->string('to_status');
            $table->text('reason')->nullable();
            $table->boolean('forced')->default(false);
            $table->json('checklist')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['accounting_period_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_period_audit_logs');
    }
};