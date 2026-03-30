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
<<<<<<< HEAD
            $table->decimal('monthly_accrual_rate', 4, 2)->nullable();
            $table->boolean('allow_advance')->default(false);
=======
>>>>>>> hr
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
                'days_per_year' => 21,
<<<<<<< HEAD
                'monthly_accrual_rate' => 1.75,
                'allow_advance' => true,
=======
>>>>>>> hr
                'color' => 'emerald',
                'icon' => 'mdi-palm-tree',
                'description' => 'Kenya statutory minimum annual leave: 21 working days, earned at 1.75 days per completed month.',
                'is_active' => true,
                'requires_attachment' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Sick Leave',
                'code' => 'SICK',
                'days_per_year' => 14,
<<<<<<< HEAD
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
=======
>>>>>>> hr
                'color' => 'blue',
                'icon' => 'mdi-medical-bag',
                'description' => 'Kenya statutory sick leave baseline: 7 days full pay and 7 days half pay after two months of service.',
                'is_active' => true,
                'requires_attachment' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Maternity Leave',
                'code' => 'MATERNITY',
                'days_per_year' => 90,
<<<<<<< HEAD
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
=======
>>>>>>> hr
                'color' => 'amber',
                'icon' => 'mdi-baby-carriage',
                'description' => 'Kenya statutory maternity leave: 3 months with full pay.',
                'is_active' => true,
                'requires_attachment' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Paternity Leave',
                'code' => 'PATERNITY',
                'days_per_year' => 14,
<<<<<<< HEAD
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
=======
>>>>>>> hr
                'color' => 'green',
                'icon' => 'mdi-human-male-child',
                'description' => 'Kenya statutory paternity leave: 2 weeks with full pay.',
                'is_active' => true,
                'requires_attachment' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Unpaid Leave',
                'code' => 'UNPAID',
                'days_per_year' => 0,
<<<<<<< HEAD
                'monthly_accrual_rate' => null,
                'allow_advance' => false,
=======
>>>>>>> hr
                'color' => 'slate',
                'icon' => 'mdi-cash-remove',
                'description' => 'Policy-controlled unpaid leave. No statutory monthly accrual.',
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
