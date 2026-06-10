<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_tasks', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropColumn(['team_id', 'setup_teams_confirmed', 'team_confirmation_notes']);
        });
    }

    public function down(): void
    {
        Schema::table('logistics_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->after('project_id');
            $table->foreign('team_id')->references('id')->on('teams_tasks')->nullOnDelete();
            $table->boolean('setup_teams_confirmed')->default(false);
            $table->text('team_confirmation_notes')->nullable();
        });
    }
};
