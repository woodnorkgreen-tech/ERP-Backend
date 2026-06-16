<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiry_tasks', function (Blueprint $table) {
            $table->index('department_id',    'et_department_id_idx');
            $table->index('type',             'et_type_idx');
            $table->index('assigned_user_id', 'et_assigned_user_idx');
            $table->index(['project_enquiry_id', 'type'], 'et_enquiry_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('enquiry_tasks', function (Blueprint $table) {
            $table->dropIndex('et_department_id_idx');
            $table->dropIndex('et_type_idx');
            $table->dropIndex('et_assigned_user_idx');
            $table->dropIndex('et_enquiry_type_idx');
        });
    }
};
