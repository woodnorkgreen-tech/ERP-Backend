<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->text('equipment_involved')->nullable()->after('witnesses');
            $table->boolean('is_guest_submission')->default(false)->after('date_reported');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['equipment_involved', 'is_guest_submission']);
        });
    }
};

