<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_elements', function (Blueprint $table) {
            $table->uuid('persistent_id')->nullable()->unique()->after('id');
        });

        // Generate persistent IDs for existing records
        $elements = DB::table('project_elements')->whereNull('persistent_id')->get();
        foreach ($elements as $element) {
            DB::table('project_elements')
                ->where('id', $element->id)
                ->update(['persistent_id' => Str::uuid()]);
        }

        // Make persistent_id not nullable after populating
        Schema::table('project_elements', function (Blueprint $table) {
            $table->uuid('persistent_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_elements', function (Blueprint $table) {
            $table->dropColumn('persistent_id');
        });
    }
};
