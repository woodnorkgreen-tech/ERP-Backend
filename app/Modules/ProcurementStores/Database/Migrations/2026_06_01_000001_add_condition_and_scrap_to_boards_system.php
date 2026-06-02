<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add condition grade to the board record (the latest assessed grade)
        Schema::table('boards', function (Blueprint $table) {
            $table->string('condition_grade', 10)->nullable()->after('current_value');
        });

        // Add condition grade + scrap reason to every movement entry so the
        // full condition history is preserved in the immutable audit log
        Schema::table('board_movements', function (Blueprint $table) {
            $table->string('condition_grade', 10)->nullable()->after('notes');
            $table->string('scrap_reason_code', 80)->nullable()->after('condition_grade');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn('condition_grade');
        });

        Schema::table('board_movements', function (Blueprint $table) {
            $table->dropColumn(['condition_grade', 'scrap_reason_code']);
        });
    }
};
