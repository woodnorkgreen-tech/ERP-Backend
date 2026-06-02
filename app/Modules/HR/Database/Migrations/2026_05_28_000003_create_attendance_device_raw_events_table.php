<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_device_raw_events', function (Blueprint $table) {
            $table->id();
            $table->string('person_id');
            $table->string('person_name');
            $table->datetime('event_datetime');
            $table->string('check_point')->nullable();
            $table->string('department')->nullable();
            $table->enum('source', ['api_sync', 'csv_upload'])->default('api_sync');
            $table->foreignId('sync_log_id')
                  ->nullable()
                  ->constrained('attendance_device_sync_logs')
                  ->onDelete('set null');
            $table->timestamps();

            $table->unique(['person_id', 'event_datetime']);
            $table->index('person_id');
            $table->index('event_datetime');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_device_raw_events');
    }
};
