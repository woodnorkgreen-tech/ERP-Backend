<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('module');
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable();
            $table->enum('urgency', ['info', 'success', 'warning', 'critical'])->default('info');
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->boolean('is_starred')->default(false);
            $table->timestamp('starred_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('module');
            $table->index('is_read');
            $table->index('is_starred');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
