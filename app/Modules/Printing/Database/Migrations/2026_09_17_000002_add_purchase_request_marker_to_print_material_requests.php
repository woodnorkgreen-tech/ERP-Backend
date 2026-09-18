<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_material_requests', function (Blueprint $table) {
            $table->timestamp('purchase_requested_at')->nullable()->after('status')->index();
        });

        // Preserve explicit escalations made before this marker existed.
        DB::table('print_material_requests')
            ->where('status', 'awaiting_purchase')
            ->update(['purchase_requested_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('print_material_requests', function (Blueprint $table) {
            $table->dropColumn('purchase_requested_at');
        });
    }
};
