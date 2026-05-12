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
    Schema::table('vehicles', function (Blueprint $table) {
        $table->string('make')->nullable()->after('plate_number');
        $table->string('model')->nullable()->after('make');
        $table->string('photo_front')->nullable()->after('status');
        $table->string('photo_side')->nullable()->after('photo_front');
    });
}

public function down(): void
{
    Schema::table('vehicles', function (Blueprint $table) {
        $table->dropColumn(['make', 'model', 'photo_front', 'photo_side']);
    });
}
};
