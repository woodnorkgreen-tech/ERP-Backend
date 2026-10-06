<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ann/Kevin's follow-up on the loading-timeline feature: loading duration
 * shouldn't just be a bare number of minutes typed in — a small load and a
 * big load don't take the same time, so a Load Size tag lets the create
 * forms (web + mobile) offer sensible default minutes per size while still
 * letting someone override the minutes by hand. Purely informational on
 * this column; the number that actually drives the calculation stays
 * estimated_loading_minutes, exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            $table->string('load_size', 10)->nullable()->after('estimated_loading_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            $table->dropColumn('load_size');
        });
    }
};
