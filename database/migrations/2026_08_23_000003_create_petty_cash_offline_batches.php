<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('petty_cash_offline_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_reference')->unique();
            $table->string('workbook_version', 20);
            $table->string('original_filename');
            $table->string('file_sha256', 64)->unique();
            $table->string('status', 30)->default('uploaded')->index();
            $table->json('totals')->nullable();
            $table->json('validation_summary')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('petty_cash_offline_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('petty_cash_offline_batches')->cascadeOnDelete();
            $table->string('record_type', 30)->index();
            $table->unsignedInteger('row_number');
            $table->string('offline_reference', 100);
            $table->json('payload');
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->string('status', 30)->default('staged')->index();
            $table->string('posted_type')->nullable();
            $table->unsignedBigInteger('posted_id')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'record_type', 'offline_reference'], 'pc_offline_row_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_offline_rows');
        Schema::dropIfExists('petty_cash_offline_batches');
    }
};
