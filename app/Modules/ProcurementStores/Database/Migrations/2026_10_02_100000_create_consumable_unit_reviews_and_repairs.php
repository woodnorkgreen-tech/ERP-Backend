<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('consumable_unit_counts', fn (Blueprint $t) => $t->unsignedBigInteger('last_movement_id')->nullable());
        Schema::create('consumable_unit_count_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('count_id')->unique()->constrained('consumable_unit_counts')->restrictOnDelete();
            $t->foreignId('consumable_unit_id')->constrained()->restrictOnDelete();
            $t->foreignId('material_id')->constrained('library_materials')->restrictOnDelete();
            foreach (['system_quantity', 'physical_quantity', 'variance', 'resulting_quantity'] as $name) $t->decimal($name,20,6);
            $t->unsignedBigInteger('reviewer_id'); $t->timestamp('reviewed_at');
            $t->string('decision',20); $t->text('reason');
            $t->foreignId('adjustment_log_id')->nullable()->constrained('inventory_logs')->restrictOnDelete();
            $t->unsignedBigInteger('journal_entry_id')->nullable(); $t->string('accounting_status',50);
        });
        Schema::create('consumable_unit_valuation_repairs', function (Blueprint $t) {
            $t->id(); $t->foreignId('consumable_unit_id')->constrained()->restrictOnDelete();
            $t->decimal('previous_unit_cost',20,8)->nullable(); $t->decimal('previous_value',20,2)->nullable();
            $t->decimal('new_unit_cost',20,8); $t->decimal('new_value',20,2);
            $t->string('source',50); $t->unsignedBigInteger('source_log_id');
            $t->string('evidence_reference'); $t->text('evidence'); $t->text('reason');
            $t->unsignedBigInteger('actor_id'); $t->timestamp('created_at');
        });
    }
    public function down(): void { Schema::dropIfExists('consumable_unit_valuation_repairs'); Schema::dropIfExists('consumable_unit_count_reviews'); Schema::table('consumable_unit_counts', fn (Blueprint $t) => $t->dropColumn('last_movement_id')); }
};
