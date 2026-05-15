<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transport_items', function (Blueprint $table) {
            $table->boolean('is_returnable')->default(false)->after('main_category');
            $table->string('sub_type', 50)->nullable()->after('is_returnable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transport_items', function (Blueprint $table) {
            $table->dropColumn(['is_returnable', 'sub_type']);
        });
    }
};
