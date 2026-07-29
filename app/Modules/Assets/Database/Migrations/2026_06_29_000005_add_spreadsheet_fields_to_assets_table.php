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
            $table->string('subcategory', 100)->nullable()->after('category')
                ->comment('Synced from category_id when the chosen category has a parent');

            $table->string('manufacturer', 150)->nullable()->after('subcategory');
            $table->string('model', 150)->nullable()->after('manufacturer');
            $table->string('serial_number', 150)->nullable()->after('model')
                ->comment("Manufacturer's serial number — distinct from asset_code (our tag)");
            $table->string('specifications', 255)->nullable()->after('serial_number')
                ->comment('Free-text spec field — e.g. "Process type and speed" for machinery');
            $table->unsignedInteger('qty')->default(1)->after('specifications');

            $table->renameColumn('purchase_cost', 'purchase_cost_kes');
            $table->decimal('purchase_cost_usd', 15, 2)->nullable()->after('purchase_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['subcategory', 'manufacturer', 'model', 'serial_number', 'specifications', 'qty', 'purchase_cost_usd']);
            $table->renameColumn('purchase_cost_kes', 'purchase_cost');
        });
    }
};
