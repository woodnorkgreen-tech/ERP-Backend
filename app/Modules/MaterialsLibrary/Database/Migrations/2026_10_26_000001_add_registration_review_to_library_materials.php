<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report 79C: why a material was registered although similar items existed —
 * the candidates the person looked at, the reason they gave, who and when.
 * Null for an item that had no look-alike when it was registered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('library_materials', 'registration_review')) {
            return;
        }
        Schema::table('library_materials', function (Blueprint $table) {
            $table->json('registration_review')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('library_materials', 'registration_review')) {
            Schema::table('library_materials', fn (Blueprint $table) => $table->dropColumn('registration_review'));
        }
    }
};
