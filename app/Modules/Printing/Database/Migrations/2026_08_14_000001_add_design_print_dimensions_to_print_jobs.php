<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->decimal('design_height_m', 12, 3)->nullable()->after('artwork_version');
            $table->decimal('design_length_m', 12, 3)->nullable()->after('design_height_m');
            $table->decimal('print_width_m', 12, 3)->nullable()->after('design_length_m');
            $table->decimal('running_length_m', 12, 3)->nullable()->after('print_width_m');
            $table->decimal('artwork_quantity', 12, 3)->nullable()->after('running_length_m');
        });

        DB::table('print_jobs')
            ->join('design_items', 'design_items.id', '=', 'print_jobs.design_item_id')
            ->whereNull('print_jobs.print_width_m')
            ->update([
                'print_jobs.design_height_m' => DB::raw('design_items.width_m'),
                'print_jobs.design_length_m' => DB::raw('design_items.length_m'),
                'print_jobs.print_width_m' => DB::raw('design_items.width_m'),
                'print_jobs.running_length_m' => DB::raw('design_items.length_m'),
                'print_jobs.artwork_quantity' => DB::raw('design_items.quantity'),
            ]);
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'design_height_m',
                'design_length_m',
                'print_width_m',
                'running_length_m',
                'artwork_quantity',
            ]);
        });
    }
};
