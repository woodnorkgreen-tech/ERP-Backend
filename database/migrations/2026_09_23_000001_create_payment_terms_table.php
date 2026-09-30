<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W1-7: configurable payment-term templates.
 *
 * Deliberately seeded with nothing (see the accompanying seeder, which is not
 * run automatically) — the register is explicit that "Due on Receipt"/7/14/30
 * days are illustrative examples only, not confirmed WNG commercial policy.
 * The architecture supports configuration; Finance supplies the real values.
 * An invoice's own `due_date` remains manually overridable regardless of
 * whether a term is selected, so the system works correctly with zero
 * configured terms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_terms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // Null means "custom" — the due date is picked by hand rather than
            // computed from the invoice date.
            $table->unsignedSmallInteger('days')->nullable();
            $table->boolean('is_custom')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_terms');
    }
};
