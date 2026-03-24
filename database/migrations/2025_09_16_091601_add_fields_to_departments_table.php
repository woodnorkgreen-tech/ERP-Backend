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
        Schema::table('departments', function (Blueprint $table) {
            $table->string('name')->unique()->after('id');
            $table->text('description')->nullable()->after('name');
            $table->foreignId('manager_id')->nullable()->constrained('employees')->onDelete('set null')->after('description');
            $table->decimal('budget', 15, 2)->nullable()->after('manager_id');
            $table->string('location')->nullable()->after('budget');

            $table->index('manager_id');
            $table->index('location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropIndex(['manager_id']);
            $table->dropIndex(['location']);
            $table->dropForeign(['manager_id']);
            $table->dropColumn(['name', 'description', 'manager_id', 'budget', 'location']);
        });
    }
};