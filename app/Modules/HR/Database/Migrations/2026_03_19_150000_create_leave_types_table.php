<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->unsignedSmallInteger('days_per_year')->default(0);
            $table->string('color', 32)->default('slate');
            $table->string('icon', 64)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_attachment')->default(false);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });

        DB::table('leave_types')->insert([
            [
                'name' => 'Annual Leave',
                'code' => 'ANNUAL',
                'days_per_year' => 20,
                'color' => 'emerald',
                'icon' => 'mdi-palm-tree',
                'description' => 'Standard annual leave entitlement.',
                'is_active' => true,
                'requires_attachment' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Sick Leave',
                'code' => 'SICK',
                'days_per_year' => 10,
                'color' => 'blue',
                'icon' => 'mdi-medical-bag',
                'description' => 'Leave for illness or medical recovery.',
                'is_active' => true,
                'requires_attachment' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Maternity Leave',
                'code' => 'MATERNITY',
                'days_per_year' => 90,
                'color' => 'amber',
                'icon' => 'mdi-baby-carriage',
                'description' => 'Leave for maternity and post-delivery recovery.',
                'is_active' => true,
                'requires_attachment' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Paternity Leave',
                'code' => 'PATERNITY',
                'days_per_year' => 14,
                'color' => 'green',
                'icon' => 'mdi-human-male-child',
                'description' => 'Leave for fathers after childbirth.',
                'is_active' => true,
                'requires_attachment' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Unpaid Leave',
                'code' => 'UNPAID',
                'days_per_year' => 30,
                'color' => 'slate',
                'icon' => 'mdi-cash-remove',
                'description' => 'Approved leave taken without pay.',
                'is_active' => true,
                'requires_attachment' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
