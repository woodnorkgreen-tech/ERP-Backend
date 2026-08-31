<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_elements', function (Blueprint $table) {
            $table->decimal('required_quantity', 14, 4)->default(1)->after('category');
            $table->string('unit_of_measurement', 100)->default('Pcs')->after('required_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('project_elements', function (Blueprint $table) {
            $table->dropColumn(['required_quantity', 'unit_of_measurement']);
        });
    }
};
