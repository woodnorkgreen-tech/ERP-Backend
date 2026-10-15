<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            // delivery = store -> venue (needed by a time)
            // collection = venue/other place -> store (set-down or pick-up)
            $table->string('trip_kind', 20)->default('delivery')->after('transport_arrangement');
        });
    }

    public function down(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            $table->dropColumn('trip_kind');
        });
    }
};
