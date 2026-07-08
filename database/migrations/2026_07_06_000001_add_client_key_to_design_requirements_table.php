<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_requirements', function (Blueprint $table) {
            $table->string('client_key')->nullable()->after('id');
            $table->unique(['enquiry_task_id', 'client_key'], 'design_requirements_task_client_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('design_requirements', function (Blueprint $table) {
            $table->dropUnique('design_requirements_task_client_key_unique');
            $table->dropColumn('client_key');
        });
    }
};
