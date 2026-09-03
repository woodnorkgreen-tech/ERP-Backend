<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_counts', function (Blueprint $table) {
            $table->string('mode', 30)->default('cycle_count')->after('count_number')->index();
            $table->timestamp('catalogue_snapshot_at')->nullable()->after('counted_on');
        });

        Schema::table('stock_count_items', function (Blueprint $table) {
            $table->string('entry_method', 30)->default('bulk_quantity')->after('material_id');
            $table->timestamp('material_updated_at_snapshot')->nullable()->after('entry_method');
            $table->decimal('opening_unit_cost', 18, 4)->nullable()->after('variance_quantity');
            $table->string('location_bin', 100)->nullable()->after('opening_unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('stock_count_items', function (Blueprint $table) {
            $table->dropColumn([
                'entry_method', 'material_updated_at_snapshot', 'opening_unit_cost', 'location_bin',
            ]);
        });

        Schema::table('stock_counts', function (Blueprint $table) {
            $table->dropIndex(['mode']);
            $table->dropColumn(['mode', 'catalogue_snapshot_at']);
        });
    }
};
