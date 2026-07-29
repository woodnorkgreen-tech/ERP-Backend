<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These links are now opened by unauthenticated drivers/site contacts
        // (see routes/api.php), so the *_by foreign keys to users stay null on
        // public submissions — these free-text columns capture who actually
        // confirmed, matching the respondent_info pattern already used by
        // HandoverSurvey for the same public-form scenario.
        Schema::table('logistics_loading_confirmation_links', function (Blueprint $table) {
            $table->string('confirmed_by_name')->nullable()->after('confirmed_by');
        });

        Schema::table('logistics_return_confirmation_links', function (Blueprint $table) {
            $table->string('confirmed_by_name')->nullable()->after('confirmed_by');
        });

        Schema::table('logistics_manifest_submissions', function (Blueprint $table) {
            $table->string('submitted_by_name')->nullable()->after('submitted_by');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_loading_confirmation_links', function (Blueprint $table) {
            $table->dropColumn('confirmed_by_name');
        });

        Schema::table('logistics_return_confirmation_links', function (Blueprint $table) {
            $table->dropColumn('confirmed_by_name');
        });

        Schema::table('logistics_manifest_submissions', function (Blueprint $table) {
            $table->dropColumn('submitted_by_name');
        });
    }
};
