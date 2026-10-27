<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report 79C: a Tools & Equipment row on a loading sheet may name the
 * registered asset going to site. A reference only — choosing it creates no
 * hire request, material, stock or cost; hand-overs stay in the Assets module.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transport_items', 'asset_id')) {
            return;
        }
        Schema::table('transport_items', function (Blueprint $table) {
            $table->foreignId('asset_id')->nullable()->after('main_category')->constrained('assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('transport_items', 'asset_id')) {
            Schema::table('transport_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('asset_id');
            });
        }
    }
};
