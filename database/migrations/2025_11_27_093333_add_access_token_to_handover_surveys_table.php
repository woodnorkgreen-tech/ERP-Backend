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
        Schema::table('handover_surveys', function (Blueprint $table) {
            $table->string('access_token', 64)->nullable()->unique()->after('task_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('handover_surveys', function (Blueprint $table) {
            $table->dropColumn('access_token');
        });
    }
};
