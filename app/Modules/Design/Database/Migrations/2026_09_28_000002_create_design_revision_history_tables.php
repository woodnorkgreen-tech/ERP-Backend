<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_item_id')->constrained('design_items')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->text('change_summary');
            $table->string('status', 30)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('approval_evidence')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('handed_off_at')->nullable();
            $table->timestamps();
            $table->unique(['design_item_id', 'version_number']);
        });

        Schema::create('design_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_item_id')->constrained('design_items')->cascadeOnDelete();
            $table->foreignId('against_revision_id')->nullable()->constrained('design_revisions')->nullOnDelete();
            $table->foreignId('addressed_by_revision_id')->nullable()->constrained('design_revisions')->nullOnDelete();
            $table->text('request_text');
            $table->string('requested_by_name')->nullable();
            $table->timestamp('received_at');
            $table->string('status', 30)->default('open');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['design_item_id', 'status']);
        });

        Schema::create('design_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_item_id')->constrained('design_items')->cascadeOnDelete();
            $table->text('note');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('design_documents', function (Blueprint $table) {
            $table->foreignId('design_revision_id')->nullable()->after('design_item_id')->constrained('design_revisions')->nullOnDelete();
            $table->text('notes')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('design_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('design_revision_id');
            $table->dropColumn('notes');
        });
        Schema::dropIfExists('design_updates');
        Schema::dropIfExists('design_change_requests');
        Schema::dropIfExists('design_revisions');
    }
};
