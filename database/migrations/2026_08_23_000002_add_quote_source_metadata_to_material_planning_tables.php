<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_elements', fn (Blueprint $table) => $table->json('source_metadata')->nullable()->after('notes'));
        Schema::table('element_materials', fn (Blueprint $table) => $table->json('source_metadata')->nullable()->after('notes'));
    }

    public function down(): void
    {
        Schema::table('element_materials', fn (Blueprint $table) => $table->dropColumn('source_metadata'));
        Schema::table('project_elements', fn (Blueprint $table) => $table->dropColumn('source_metadata'));
    }
};
