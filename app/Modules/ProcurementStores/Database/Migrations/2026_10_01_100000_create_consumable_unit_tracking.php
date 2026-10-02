<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('consumable_units', function (Blueprint $t) {
            $t->id();
            $t->string('unit_code')->unique();
            $t->foreignId('material_id')->constrained('library_materials')->restrictOnDelete();
            $t->foreignId('parent_unit_id')->nullable()->constrained('consumable_units')->restrictOnDelete();
            $t->unsignedBigInteger('source_receipt_id')->nullable();
            $t->unsignedBigInteger('source_log_id')->nullable();
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->string('source_reference')->nullable();
            $t->string('unit_of_measure', 30);
            $t->decimal('original_quantity', 20, 6);
            $t->decimal('remaining_quantity', 20, 6);
            $t->decimal('unit_cost', 20, 8)->nullable();
            $t->decimal('original_value', 20, 2)->nullable();
            $t->decimal('remaining_value', 20, 2)->nullable();
            $t->string('status', 20)->default('UNOPENED')->index();
            $t->timestamp('received_at');
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('depleted_at')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
        });
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE consumable_units ADD CONSTRAINT cu_quantity_bounds CHECK (original_quantity > 0 AND remaining_quantity >= 0 AND remaining_quantity <= original_quantity), ADD CONSTRAINT cu_value_bounds CHECK (remaining_value IS NULL OR remaining_value >= 0), ADD CONSTRAINT cu_status CHECK (status IN ('UNOPENED', 'OPEN', 'DEPLETED', 'HOLD_REVIEW') AND (status <> 'DEPLETED' OR remaining_quantity = 0))");
        Schema::create('consumable_unit_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consumable_unit_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('inventory_log_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->unsignedBigInteger('actor_id');
            $t->string('type', 30);
            $t->decimal('quantity', 20, 6);
            $t->decimal('balance_before', 20, 6);
            $t->decimal('balance_after', 20, 6);
            $t->decimal('value', 20, 2)->nullable();
            $t->decimal('unit_cost', 20, 8)->nullable();
            $t->string('reference')->nullable();
            $t->text('reason')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('consumable_unit_counts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consumable_unit_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('actor_id');
            $t->decimal('system_quantity', 20, 6);
            $t->decimal('physical_quantity', 20, 6);
            $t->decimal('variance', 20, 6);
            $t->string('status', 30);
            $t->text('notes');
            $t->timestamp('created_at');
        });
        Schema::table('inventory_logs', function (Blueprint $t) {
            $t->foreignId('consumable_unit_id')->nullable()->constrained()->restrictOnDelete();
            $t->decimal('movement_value', 20, 2)->nullable();
            $t->decimal('quantity', 20, 6)->change();
            $t->decimal('balance_after', 20, 6)->change();
        });
        Schema::table('stocks', fn (Blueprint $t) => $t->decimal('quantity_on_hand', 20, 6)->default(0)->change());
    }

    public function down(): void
    {
        Schema::table('inventory_logs', function (Blueprint $t) {
            $t->dropConstrainedForeignId('consumable_unit_id');
            $t->dropColumn('movement_value');
        });
        Schema::dropIfExists('consumable_unit_counts');
        Schema::dropIfExists('consumable_unit_movements');
        Schema::dropIfExists('consumable_units');
    }
};
