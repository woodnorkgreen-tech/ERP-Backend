<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stores_finance_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_log_id')->constrained('inventory_logs')->cascadeOnDelete();
            $table->string('posting_type', 24);
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('cost_line_id')->nullable()->constrained('cost_lines')->nullOnDelete();
            $table->foreignId('last_retried_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['inventory_log_id', 'posting_type'], 'stores_finance_posting_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores_finance_postings');
    }
};
