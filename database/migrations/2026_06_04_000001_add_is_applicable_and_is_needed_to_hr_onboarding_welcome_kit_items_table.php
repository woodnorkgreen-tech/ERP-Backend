<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_onboarding_welcome_kit_items', function (Blueprint $table) {
            $table->boolean('is_applicable')->default(true)->after('is_ready');
            $table->boolean('is_needed')->default(true)->after('is_applicable');
        });
    }

    public function down(): void
    {
        Schema::table('hr_onboarding_welcome_kit_items', function (Blueprint $table) {
            $table->dropColumn(['is_applicable', 'is_needed']);
        });
    }
};
