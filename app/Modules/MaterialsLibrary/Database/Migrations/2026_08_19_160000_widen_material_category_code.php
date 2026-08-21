<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * varchar(6) was sized for a taxonomy a developer seeded once. Now that the
 * business creates its own categories, six characters silently truncates —
 * and a truncated code still has to stay unique, so collisions would surface
 * as a confusing save failure rather than a short name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_categories', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('material_categories', function (Blueprint $table) {
            $table->string('code', 6)->nullable()->change();
        });
    }
};
