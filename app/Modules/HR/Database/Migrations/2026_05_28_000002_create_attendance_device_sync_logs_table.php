<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_device_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('device_id');
            $table->string('device_name');
            $table->timestamp('synced_at')->useCurrent();
            $table->integer('records_imported')->default(0);
            $table->integer('records_processed')->default(0);
            $table->enum('status', ['success', 'failed', 'partial'])->default('success');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_device_sync_logs');
    }
};
