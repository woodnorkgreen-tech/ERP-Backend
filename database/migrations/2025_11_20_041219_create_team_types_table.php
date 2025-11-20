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
        Schema::create('team_types', function (Blueprint $table) {
            $table->id();
            $table->string('type_key', 191)->unique();
            $table->string('name');
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->decimal('default_hourly_rate', 8, 2)->nullable();
            $table->integer('max_members_per_team')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_types');
    }
};
