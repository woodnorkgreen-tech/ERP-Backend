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
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->json('survey_photos')->nullable()->after('prepared_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->dropColumn('survey_photos');
        });
    }
};
