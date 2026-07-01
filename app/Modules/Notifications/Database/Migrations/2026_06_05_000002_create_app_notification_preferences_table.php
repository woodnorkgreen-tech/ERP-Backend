<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->enum('channel', ['database', 'mail', 'push', 'whatsapp']);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'type', 'channel']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notification_preferences');
    }
};
