<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('design_documents', 'source')) {
                $table->enum('source', ['file', 'link'])->default('file')->after('original_name');
            }

            if (!Schema::hasColumn('design_documents', 'external_url')) {
                $table->string('external_url', 2048)->nullable()->after('source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('design_documents', function (Blueprint $table) {
            if (Schema::hasColumn('design_documents', 'external_url')) {
                $table->dropColumn('external_url');
            }

            if (Schema::hasColumn('design_documents', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
