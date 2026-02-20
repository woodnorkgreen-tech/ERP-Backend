<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('production_ncrs') || Schema::hasColumn('production_ncrs', 'items_rejected_status')) {
            return;
        }

        Schema::table('production_ncrs', function (Blueprint $table) {
            $table->string('items_rejected_status', 20)->nullable()->after('items_rejected');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('production_ncrs') || ! Schema::hasColumn('production_ncrs', 'items_rejected_status')) {
            return;
        }

        Schema::table('production_ncrs', function (Blueprint $table) {
            $table->dropColumn('items_rejected_status');
        });
    }
};
