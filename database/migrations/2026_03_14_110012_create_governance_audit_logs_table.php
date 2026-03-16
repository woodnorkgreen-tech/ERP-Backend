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
        Schema::create('governance_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_enquiry_id')->nullable()->constrained('project_enquiries')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('gate_type'); // financial, technical, etc.
            $table->string('action_status'); // authorized, blocked
            $table->string('model_type')->nullable(); // PurchaseOrder, etc.
            $table->unsignedBigInteger('model_id')->nullable();
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('governance_audit_logs');
    }
};
