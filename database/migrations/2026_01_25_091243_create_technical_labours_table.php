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
        Schema::create('technical_labours', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('id_number')->nullable();
            $table->string('specialization')->nullable(); // e.g. Electrician, Carpenter, Rigger
            $table->decimal('day_rate', 12, 2)->default(0);
            $table->string('status')->default('active'); // active, inactive, blacklisted
            $table->decimal('rating', 3, 2)->default(5.00); // 1-5 scale
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('technical_labours');
    }
};
