<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_print_options', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('label', 191);
            $table->decimal('bleed_per_side_m', 12, 3)->nullable();
            $table->timestamps();
            $table->unique(['kind', 'label']);
            $table->unique(['kind', 'bleed_per_side_m']);
        });

        Schema::table('design_items', function (Blueprint $table) {
            $table->string('application_surface', 191)->nullable();
            $table->decimal('bleed_per_side_m', 12, 3)->nullable();
        });

        Schema::table('print_jobs', function (Blueprint $table) {
            $table->string('application_surface', 191)->nullable();
            $table->decimal('bleed_per_side_m', 12, 3)->nullable();
        });

        $now = now();
        $surfaces = ['Metal/Steel Structure', 'Board/MDF', 'Wall/Glass/Existing Surface', 'Custom/Other', 'N/A'];
        foreach ($surfaces as $surface) {
            DB::table('design_print_options')->insert([
                'kind' => 'surface', 'label' => $surface, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ([0, 0.1, 0.2, 0.3, 0.4, 0.5] as $metres) {
            DB::table('design_print_options')->insert([
                'kind' => 'bleed',
                'label' => $metres == 0 ? 'N/A' : (int) ($metres * 100) . 'cm / ' . $metres . 'm',
                'bleed_per_side_m' => $metres,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropColumn(['application_surface', 'bleed_per_side_m']);
        });
        Schema::table('design_items', function (Blueprint $table) {
            $table->dropColumn(['application_surface', 'bleed_per_side_m']);
        });
        Schema::dropIfExists('design_print_options');
    }
};
