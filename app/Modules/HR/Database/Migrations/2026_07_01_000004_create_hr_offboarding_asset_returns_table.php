<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_offboarding_asset_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('offboarding_case_id');
            $table->string('item_name');
            $table->boolean('is_returned')->default(false);
            $table->enum('condition', ['good', 'damaged', 'lost'])->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->boolean('is_applicable')->default(true);
            $table->boolean('is_needed')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('offboarding_case_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_offboarding_asset_returns');
    }
};
