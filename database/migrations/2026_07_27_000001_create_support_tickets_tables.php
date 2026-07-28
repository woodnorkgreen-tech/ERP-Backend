<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number')->nullable()->unique();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 160);
            $table->text('description');
            $table->enum('type', ['bug', 'improvement', 'support'])->default('support');
            $table->enum('category', ['erp', 'access', 'device', 'network', 'email', 'other'])->default('erp');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->enum('status', ['open', 'assigned', 'in_progress', 'waiting_on_user', 'resolved', 'closed'])->default('open');
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['reporter_id', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index(['status', 'priority']);
            $table->index('last_activity_at');
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('message');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();
            $table->index(['support_ticket_id', 'created_at']);
        });

        Schema::create('support_ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->timestamps();
            $table->index('support_ticket_id');
        });

        Schema::create('support_ticket_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->json('changes')->nullable();
            $table->timestamps();
            $table->index(['support_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_activities');
        Schema::dropIfExists('support_ticket_attachments');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
