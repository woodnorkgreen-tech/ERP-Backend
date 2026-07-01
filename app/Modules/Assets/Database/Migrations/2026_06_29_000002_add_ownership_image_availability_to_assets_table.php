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
        Schema::table('assets', function (Blueprint $table) {
            $table->string('ownership_type', 20)->default('Company')->after('asset_code')
                ->comment('Company or Client');
            $table->string('client_name', 150)->nullable()->after('ownership_type')
                ->comment('Required when ownership_type is Client');
            $table->string('image_path')->nullable()->after('client_name');
            $table->boolean('is_available')->default(true)->after('status')
                ->comment('Toggled by the employee in charge (assigned_to)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['ownership_type', 'client_name', 'image_path', 'is_available']);
        });
    }
};
