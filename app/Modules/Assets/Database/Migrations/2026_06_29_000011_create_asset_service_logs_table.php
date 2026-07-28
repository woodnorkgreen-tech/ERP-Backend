<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_service_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->date('service_date');
            $table->enum('service_type', ['Service', 'Maintenance', 'Repair', 'Inspection'])->default('Service');
            $table->text('notes')->nullable();
            $table->string('serviced_by')->nullable(); // free text — name of person/vendor
            $table->date('next_service_date')->nullable(); // sets the asset's next due date
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_service_logs');
    }
};
