<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_onboarding_welcome_kit_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('onboarding_case_id');
            $table->string('item_name');
            $table->boolean('is_ready')->default(false);
            $table->unsignedBigInteger('marked_ready_by')->nullable();
            $table->timestamp('marked_ready_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('onboarding_case_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_onboarding_welcome_kit_items');
    }
};
