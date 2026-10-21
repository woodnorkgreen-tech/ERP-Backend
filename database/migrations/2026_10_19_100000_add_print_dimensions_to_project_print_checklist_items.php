<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_print_checklist_items', function (Blueprint $table) {
            $table->string('print_length', 100)->nullable()->after('print_measurement');
            $table->string('print_width', 100)->nullable()->after('print_length');
            $table->string('print_height', 100)->nullable()->after('print_width');
        });
    }

    public function down(): void
    {
        Schema::table('project_print_checklist_items', function (Blueprint $table) {
            $table->dropColumn(['print_length', 'print_width', 'print_height']);
        });
    }
};
