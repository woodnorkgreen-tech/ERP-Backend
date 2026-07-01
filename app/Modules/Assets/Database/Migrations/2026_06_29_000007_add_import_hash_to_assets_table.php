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
        Schema::table('assets', function (Blueprint $table) {
            $table->string('import_hash', 64)->nullable()->after('asset_code')
                ->comment('Content fingerprint for rows imported without an explicit Asset Tag — lets re-importing the same file update instead of duplicate');
            $table->index('import_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('import_hash');
        });
    }
};
