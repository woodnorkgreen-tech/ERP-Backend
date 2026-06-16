<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_onboarding_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('onboarding_case_id');
            $table->enum('card_type', ['hr', 'it', 'sops', 'welcome_kit', 'documents', 'day_1']);
            $table->string('title');
            $table->enum('status', ['locked', 'pending', 'in_progress', 'completed'])->default('pending');
            $table->boolean('is_locked')->default(false);
            $table->timestamp('unlocked_at')->nullable();
            $table->unsignedBigInteger('unlocked_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->unsignedTinyInteger('sequence_order')->default(0);
            $table->decimal('progress', 5, 2)->default(0);
            $table->timestamps();

            $table->index('onboarding_case_id');
            $table->index(['onboarding_case_id', 'card_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_onboarding_cards');
    }
};
