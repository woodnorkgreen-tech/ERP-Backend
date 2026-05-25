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
        Schema::table('project_elements', function (Blueprint $table) {
            $table->string('scope_id', 100)->nullable()->after('template_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_elements', function (Blueprint $table) {
            $table->dropColumn('scope_id');
        });
    }
};
