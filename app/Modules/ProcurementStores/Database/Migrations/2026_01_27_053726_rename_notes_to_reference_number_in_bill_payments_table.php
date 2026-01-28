<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->renameColumn('notes', 'reference_number');
        });
    }

    public function down()
    {
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->renameColumn('reference_number', 'notes');
        });
    }
};