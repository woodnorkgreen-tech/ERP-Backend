<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_no', 32)->unique();
            $table->date('posting_date')->index();
            $table->foreignId('accounting_period_id')->nullable()->constrained('accounting_periods')->nullOnDelete();
            
            $table->foreignId('cost_line_id')->nullable()->constrained('cost_lines')->nullOnDelete();
            $table->foreignId('spend_voucher_id')->nullable()->constrained('spend_vouchers')->nullOnDelete();
            
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_ref')->nullable();
            
            $table->text('description')->nullable();
            $table->decimal('total_debit', 14, 2)->default(0);
            $table->decimal('total_credit', 14, 2)->default(0);
            
            $table->enum('status', ['draft', 'posted', 'reversed'])->default('posted')->index();
            // Exactly one compensating journal may reverse an entry. The unique
            // key is the final idempotency guard when two queue workers race.
            $table->foreignId('reversal_of_id')->nullable()->unique()
                ->constrained('journal_entries')->nullOnDelete();
            
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts');
            
            $table->enum('entry_type', ['debit', 'credit']);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('KES');
            $table->decimal('fx_rate', 18, 8)->default(1);
            $table->decimal('base_amount', 14, 2);
            
            $table->string('description')->nullable();
            $table->foreignId('cost_centre_id')->nullable()->constrained('cost_centres')->nullOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('activities')->nullOnDelete();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('project_enquiry_id')->nullable()->index();
            
            $table->timestamps();
        });

        Schema::table('cost_lines', function (Blueprint $table) {
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cost_lines', function (Blueprint $table) {
            $table->dropForeign(['journal_entry_id']);
        });

        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
    }
};
