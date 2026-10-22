<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            // Where the vehicle sets off from on a set-down / pick-up
            // (usually the store, but it can be anywhere).
            $table->string('start_location', 300)->nullable()->after('destination_lng');
            $table->decimal('start_lat', 10, 7)->nullable()->after('start_location');
            $table->decimal('start_lng', 10, 7)->nullable()->after('start_lat');
        });
    }

    public function down(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            $table->dropColumn(['start_location', 'start_lat', 'start_lng']);
        });
    }
};
