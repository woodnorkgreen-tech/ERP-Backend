<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_offboarding_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('offboarding_case_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('offboarding_case_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_offboarding_activity_logs');
    }
};
