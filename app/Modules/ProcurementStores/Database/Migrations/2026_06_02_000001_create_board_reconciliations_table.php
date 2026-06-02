<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reconciler_name');          // typed name (may differ from user record)
            $table->integer('physical_count')->nullable();
            $table->integer('system_count');            // snapshot at time of completion
            $table->integer('variance')->nullable();    // physical - system
            $table->json('steps_completed');            // [true, true, false, ...] — 6 booleans
            $table->json('status_snapshot');            // { Available: 4, Allocated: 2, ... }
            $table->text('gap_notes')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_reconciliations');
    }
};
