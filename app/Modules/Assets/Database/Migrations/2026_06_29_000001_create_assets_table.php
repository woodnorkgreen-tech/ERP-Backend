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
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code', 50)->unique()->comment('Asset tag, e.g. AST-000123');
            $table->string('name')->comment('Asset / item name');
            $table->string('category', 100)->nullable()->comment('e.g. IT Equipment, Furniture, Vehicle, Tools');
            $table->string('status', 30)->default('Active')->comment('Active, In Repair, Retired, Disposed, Lost');
            $table->string('condition', 30)->nullable()->comment('New, Good, Fair, Poor');

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Employee custodian of the asset');
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();

            $table->string('location', 150)->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 15, 2)->nullable();
            $table->decimal('current_value', 15, 2)->nullable();
            $table->string('supplier', 150)->nullable();
            $table->date('warranty_expiry')->nullable();
            $table->text('notes')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('asset_code');
            $table->index('category');
            $table->index('status');
            $table->index('is_active');
            $table->index('assigned_to');
            $table->index('department_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
