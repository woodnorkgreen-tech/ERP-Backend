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
        Schema::table('teams_members', function (Blueprint $table) {
            $table->foreignId('technical_labour_id')->nullable()->constrained('technical_labours')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('teams_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('technical_labour_id');
        });
    }
};
