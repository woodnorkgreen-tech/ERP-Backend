<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_onboarding_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('onboarding_case_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('onboarding_case_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_onboarding_activity_logs');
    }
};
