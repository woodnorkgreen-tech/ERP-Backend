<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->decimal('mobilization_threshold_percentage', 5, 2)->default(70)->after('finance_released_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_enquiries', fn (Blueprint $table) => $table->dropColumn('mobilization_threshold_percentage'));
    }
};
