<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('player_id');
            $table->enum('platform', ['android', 'ios', 'web']);
            $table->timestamps();

            $table->unique(['user_id', 'player_id']);
            $table->index('platform');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_device_tokens');
    }
};
