<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            // Optional link to a specific project/enquiry for context.
            $table->unsignedBigInteger('enquiry_id')->nullable();
            // The staff member who logged the interaction.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20)->index(); // call | email | note | meeting
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            // When the interaction actually happened (may differ from created_at).
            $table->dateTime('interaction_at')->index();
            $table->timestamps();

            $table->index(['client_id', 'interaction_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_interactions');
    }
};
