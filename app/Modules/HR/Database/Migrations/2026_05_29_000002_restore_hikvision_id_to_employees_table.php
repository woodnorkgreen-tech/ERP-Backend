<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('employees', 'hikvision_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('hikvision_id')->nullable()->unique()->after('employee_id')
                    ->comment('Device Person ID from Hikvision fingerprint scanner');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'hikvision_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropUnique(['hikvision_id']);
                $table->dropColumn('hikvision_id');
            });
        }
    }
};
