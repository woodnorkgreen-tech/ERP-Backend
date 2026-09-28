<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_items', function (Blueprint $table) {
            $table->string('destination', 30)->nullable()->after('stream');
            $table->index(['stream', 'destination']);
        });
    }

    public function down(): void
    {
        Schema::table('design_items', function (Blueprint $table) {
            $table->dropIndex(['stream', 'destination']);
            $table->dropColumn('destination');
        });
    }
};
