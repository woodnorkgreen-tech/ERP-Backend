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
        Schema::create('task_quote_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_task_id')->constrained('enquiry_tasks')->onDelete('cascade');
            $table->json('project_info')->nullable();
            $table->boolean('budget_imported')->default(false);
            $table->json('materials')->nullable();
            $table->json('labour')->nullable();
            $table->json('expenses')->nullable();
            $table->json('logistics')->nullable();
            $table->json('margins')->nullable();
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('vat_percentage', 5, 2)->default(16);
            $table->boolean('vat_enabled')->default(true);
            $table->json('totals')->nullable();
            $table->enum('status', ['draft', 'pending', 'approved', 'rejected'])->default('draft');
            $table->timestamps();

            $table->unique('enquiry_task_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_quote_data');
    }
};
