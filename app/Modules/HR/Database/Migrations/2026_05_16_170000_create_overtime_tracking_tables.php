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
        // 1. OT Entries Table
        Schema::create('ot_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('project_enquiries')->nullOnDelete();
            $table->date('work_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->decimal('hours', 8, 2);
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('draft'); // draft, submitted, under_review, approved, rejected, done
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supervisor_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('supervisor_approved_at')->nullable();
            $table->foreignId('hr_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hr_approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->foreignId('supersedes_entry_id')->nullable()->constrained('ot_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['project_id', 'work_date']);
        });

        // 2. Ledger Entries Table (Append-only)
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->enum('kind', ['credit', 'debit', 'expiry']);
            $table->decimal('hours', 8, 2);
            $table->decimal('balance_after', 10, 2);
            $table->foreignId('ot_entry_id')->nullable()->constrained('ot_entries')->nullOnDelete();
            $table->unsignedBigInteger('compensation_id')->nullable(); // Will constrain after table creation
            $table->string('source_type'); // e.g., 'ot_approval', 'manual_adjustment', 'compensation_use'
            $table->jsonb('source_snapshot')->nullable();
            $table->string('chain_hash')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['employee_id', 'kind']);
            $table->index('occurred_at');
        });

        // 3. Compensations Table
        Schema::create('compensations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('comp_date');
            $table->enum('type', ['full_day', 'half_day']);
            $table->decimal('hours', 8, 2);
            $table->boolean('project_conflict_check')->default(false);
            $table->string('status', 32)->default('pending'); // pending, approved, rejected
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('comp_date');
        });

        // Add foreign key constraint to ledger_entries for compensation_id
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreign('compensation_id')->references('id')->on('compensations')->nullOnDelete();
        });

        // 4. OT Flags Table (Intelligence)
        Schema::create('ot_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ot_entry_id')->constrained('ot_entries')->cascadeOnDelete();
            $table->string('type'); // repeated_late, excessive_hours, overlapping_projects, weekend_pattern, burnout_risk
            $table->enum('severity', ['low', 'medium', 'high']);
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['ot_entry_id', 'type']);
        });

        // 5. System Events Table (Global Audit)
        Schema::create('system_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('action');
            $table->jsonb('payload')->nullable();
            $table->string('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index('actor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_events');
        Schema::dropIfExists('ot_flags');
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['compensation_id']);
        });
        Schema::dropIfExists('compensations');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ot_entries');
    }
};
