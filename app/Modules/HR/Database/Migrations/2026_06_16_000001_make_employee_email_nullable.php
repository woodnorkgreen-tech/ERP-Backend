<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make employees.email nullable so specialists without an email can be
     * promoted to the staff roster without inventing a placeholder address.
     * The unique index is retained — MariaDB permits multiple NULLs in it.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('email', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('email', 255)->nullable(false)->change();
        });
    }
};
