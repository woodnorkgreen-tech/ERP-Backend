<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sync_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status')->default('queued')->index();
            $table->foreignId('sync_log_id')
                ->nullable()
                ->constrained('attendance_device_sync_logs')
                ->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_sync_requests');
    }
};
