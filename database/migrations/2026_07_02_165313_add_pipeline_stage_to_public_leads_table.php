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
        Schema::table('public_leads', function (Blueprint $table) {
            $table->string('pipeline_stage')->default('new_lead')->after('status');
            $table->timestamp('stage_updated_at')->nullable()->after('pipeline_stage');
            $table->unsignedBigInteger('stage_updated_by')->nullable()->after('stage_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('public_leads', function (Blueprint $table) {
            $table->dropColumn(['pipeline_stage', 'stage_updated_at', 'stage_updated_by']);
        });
    }
};
