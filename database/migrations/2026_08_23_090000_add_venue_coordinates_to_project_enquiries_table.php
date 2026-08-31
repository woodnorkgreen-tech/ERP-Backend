<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->decimal('venue_lat', 10, 7)->nullable()->after('venue');
            $table->decimal('venue_lng', 10, 7)->nullable()->after('venue_lat');
            $table->string('venue_place_id')->nullable()->after('venue_lng');
        });
    }

    public function down(): void
    {
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->dropColumn(['venue_lat', 'venue_lng', 'venue_place_id']);
        });
    }
};
