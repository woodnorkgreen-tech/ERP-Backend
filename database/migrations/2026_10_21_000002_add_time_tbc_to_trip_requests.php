<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            // The trip is planned for a day but the exact time is still to be
            // communicated. It is set later, which recalculates the timeline.
            $table->boolean('time_tbc')->default(false)->after('required_delivery_at');
        });
    }

    public function down(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            $table->dropColumn('time_tbc');
        });
    }
};
