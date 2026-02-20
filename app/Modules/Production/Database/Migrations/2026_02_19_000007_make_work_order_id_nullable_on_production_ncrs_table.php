<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('production_ncrs') || ! Schema::hasColumn('production_ncrs', 'work_order_id')) {
            return;
        }

        Schema::table('production_ncrs', function (Blueprint $table) {
            $table->foreignId('work_order_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('production_ncrs') || ! Schema::hasColumn('production_ncrs', 'work_order_id')) {
            return;
        }

        Schema::table('production_ncrs', function (Blueprint $table) {
            $table->foreignId('work_order_id')->nullable(false)->change();
        });
    }
};
