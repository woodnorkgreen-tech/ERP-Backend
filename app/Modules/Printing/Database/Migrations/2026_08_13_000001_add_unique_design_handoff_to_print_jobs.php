<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->unique('design_handoff_id', 'print_jobs_design_handoff_unique');
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropUnique('print_jobs_design_handoff_unique');
        });
    }
};
