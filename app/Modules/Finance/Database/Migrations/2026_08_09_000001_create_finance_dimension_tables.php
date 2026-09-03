<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The classification dimensions every cost is tagged with.
 *
 * These are tables rather than enum columns on purpose. The rule applied
 * throughout this module: a set is an enum ONLY when it is fixed by an
 * accounting standard (IAS 7 cash-flow classes) or when each member drives a
 * distinct code branch. Anything the business adds to over time — a new
 * department, a new project stage, a new reason for spending — is a row, so
 * adding one is a data change and never a deployment.
 *
 * Reference rows are never hard-deleted; posted history points at them. Retire
 * with `is_active = false` instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Which department carried the cost. Self-parenting so Finance can roll
        // sub-centres up to a division without a reporting rewrite.
        Schema::create('cost_centres', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('cost_centres')->nullOnDelete();

            // Lets the collector default a cost centre from the submitting user's
            // HR department. Deliberately unconstrained: this module must not
            // take a migration-order dependency on the HR module.
            $table->unsignedBigInteger('hr_department_id')->nullable()->index();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // At what project stage the cost arose. Seeded from the task types already
        // defined in config/enquiry_workflow.php and mapped back through
        // `workflow_task_type`, so activity and task state can never drift apart.
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('workflow_task_type')->nullable()->index();
            $table->boolean('is_cash_bearing')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Why the cost arose. `is_exception` is what makes this dynamic: reports
        // group exception spend by this flag, never by a hardcoded list, so a new
        // cause added next year appears in the variance analysis automatically.
        Schema::create('cost_causes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_exception')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('requires_note')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Who was paid. The boolean columns drive capture-form validation, so
        // introducing a payee type that needs a KRA PIN is a row, not a release.
        Schema::create('payee_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->boolean('requires_kra_pin')->default(false);
            $table->boolean('requires_wht_review')->default(false);
            $table->boolean('requires_supplier_record')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payee_types');
        Schema::dropIfExists('cost_causes');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('cost_centres');
    }
};
