<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_manifest_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_task_id')->constrained('logistics_tasks')->cascadeOnDelete();
            $table->uuid('token')->unique();
            $table->json('categories');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('logistics_manifest_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manifest_link_id')->constrained('logistics_manifest_links')->cascadeOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->string('unit', 50);
            $table->string('main_category', 50);
            $table->string('sub_type', 50)->nullable();
            $table->boolean('is_returnable')->default(false);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('transport_item_id')->nullable()->constrained('transport_items')->nullOnDelete();
            $table->timestamps();

            $table->index(['manifest_link_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_manifest_submissions');
        Schema::dropIfExists('logistics_manifest_links');
    }
};
