<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('count_number', 40)->unique();
            $table->string('warehouse_code', 40)->default('MAIN');
            $table->string('status', 30)->default('draft');
            $table->date('counted_on');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('library_materials');
            $table->decimal('system_quantity', 18, 6);
            $table->decimal('counted_quantity', 18, 6)->nullable();
            $table->decimal('variance_quantity', 18, 6)->nullable();
            $table->text('variance_reason')->nullable();
            $table->foreignId('adjustment_log_id')->nullable()->constrained('inventory_logs')->nullOnDelete();
            $table->timestamps();
            $table->unique(['stock_count_id', 'material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
    }
};
