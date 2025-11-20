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
        Schema::create('logistics_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('logistics_checklist_id')->constrained('logistics_checklists')->onDelete('cascade');
            $table->string('item_id');
            $table->string('item_name');
            $table->enum('status', ['present', 'missing', 'coming_later'])->default('missing');
            $table->text('notes')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logistics_checklist_items');
    }
};
