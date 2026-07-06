<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_offboarding_exit_interviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('offboarding_case_id')->unique();
            $table->unsignedBigInteger('conducted_by')->nullable();
            $table->date('conducted_at')->nullable();
            $table->string('reason_for_leaving')->nullable();
            $table->text('feedback')->nullable();
            $table->boolean('would_recommend')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->enum('status', ['pending', 'completed', 'declined'])->default('pending');
            $table->text('declined_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_offboarding_exit_interviews');
    }
};
