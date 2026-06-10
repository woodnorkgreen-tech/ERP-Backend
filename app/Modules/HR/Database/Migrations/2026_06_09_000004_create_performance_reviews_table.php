<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('reviewed_by')->constrained('users')->onDelete('restrict');
            $table->date('review_date');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('overall_rating', 3, 1);  // 0.0 – 5.0
            $table->text('notes')->nullable();
            $table->text('recommendations')->nullable();
            $table->enum('status', ['draft', 'finalised'])->default('draft');
            $table->timestamps();

            $table->index('employee_id');
            $table->index('review_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_reviews');
    }
};
