<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Nullable — existing records have no value until an admin fills it
            // in. Drives gender-restricted leave types (Maternity/Paternity);
            // left null, an employee is offered every leave type unrestricted
            // rather than being wrongly locked out of one for missing data.
            $table->string('gender')->nullable()->after('date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
