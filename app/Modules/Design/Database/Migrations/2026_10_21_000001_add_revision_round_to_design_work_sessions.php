<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_work_sessions', function (Blueprint $table) {
            $table->unsignedInteger('revision_round')->nullable()->after('design_item_id');
            $table->text('stop_reason')->nullable()->after('end_type');
        });
    }

    public function down(): void
    {
        Schema::table('design_work_sessions', function (Blueprint $table) {
            $table->dropColumn(['revision_round', 'stop_reason']);
        });
    }
};
