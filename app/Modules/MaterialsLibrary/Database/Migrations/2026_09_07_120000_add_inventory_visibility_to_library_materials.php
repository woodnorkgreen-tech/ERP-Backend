<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Being in the catalogue and being carried on the Stores shelf are two
     * different facts. The library is the register of every identity the
     * business can name; the inventory is the much shorter list Stores
     * actually keeps. Until now the second was derived from the first, so
     * every activated material appeared in Store Inventory whether or not
     * anyone intended to stock it.
     *
     * Defaults to true so the rollout changes nothing on its own — the
     * catalogue is curated deliberately from the library, not by a migration
     * guessing which items Stores carries.
     */
    public function up(): void
    {
        Schema::table('library_materials', function (Blueprint $table) {
            $table->boolean('is_inventory_visible')->default(true)->after('is_project_chargeable')->index();
        });
    }

    public function down(): void
    {
        Schema::table('library_materials', function (Blueprint $table) {
            $table->dropColumn('is_inventory_visible');
        });
    }
};
