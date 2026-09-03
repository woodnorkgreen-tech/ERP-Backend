<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_elements', function (Blueprint $table) {
            $table->string('element_type', 500)->change();
            $table->string('name', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_elements', function (Blueprint $table) {
            $table->string('element_type', 100)->change();
            $table->string('name', 255)->nullable()->change();
        });
    }
};
