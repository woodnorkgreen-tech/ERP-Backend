<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'employee_id')) {
                $table->string('employee_id')->nullable()->after('id');
            }
            if (!Schema::hasColumn('employees', 'employment_type')) {
                $table->enum('employment_type', ['full-time', 'part-time', 'contract', 'intern'])->default('full-time')->after('position');
            }
            if (!Schema::hasColumn('employees', 'manager_id')) {
                $table->foreignId('manager_id')->nullable()->constrained('employees')->onDelete('set null')->after('employment_type');
            }
            if (!Schema::hasColumn('employees', 'address')) {
                $table->text('address')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('employees', 'date_of_birth')) {
                $table->date('date_of_birth')->nullable()->after('address');
            }
            if (!Schema::hasColumn('employees', 'emergency_contact')) {
                $table->json('emergency_contact')->nullable()->after('date_of_birth');
            }
            if (!Schema::hasColumn('employees', 'performance_rating')) {
                $table->decimal('performance_rating', 2, 1)->nullable()->after('salary');
            }
            if (!Schema::hasColumn('employees', 'last_review_date')) {
                $table->date('last_review_date')->nullable()->after('performance_rating');
            }

            if (!Schema::hasColumn('employees', 'manager_id')) {
                $table->index('manager_id');
            }
        });

        // Generate unique employee IDs for existing records
        $employees = DB::table('employees')->get();
        foreach ($employees as $employee) {
            $employeeId = 'EMP' . str_pad($employee->id, 4, '0', STR_PAD_LEFT);
            DB::table('employees')->where('id', $employee->id)->update(['employee_id' => $employeeId]);
        }

        // Now make employee_id unique and not null if it exists
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'employee_id')) {
                $table->string('employee_id')->unique()->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['employee_id']);
            $table->dropIndex(['manager_id']);
            $table->dropForeign(['manager_id']);
            $table->dropColumn([
                'employee_id',
                'employment_type',
                'manager_id',
                'address',
                'date_of_birth',
                'emergency_contact',
                'performance_rating',
                'last_review_date'
            ]);
        });
    }
};
