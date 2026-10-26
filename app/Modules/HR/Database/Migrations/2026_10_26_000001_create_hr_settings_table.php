<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR policy switches HR can change without a deployment.
 *
 * First use: the salary advance cap. It ships OFF because many employees'
 * salaries are not yet recorded, and a cap on a zero salary blocks everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table('hr_settings')->insert([
            ['key' => 'salary_advance_cap_enabled', 'value' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'salary_advance_cap_percent', 'value' => '30', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_settings');
    }
};
