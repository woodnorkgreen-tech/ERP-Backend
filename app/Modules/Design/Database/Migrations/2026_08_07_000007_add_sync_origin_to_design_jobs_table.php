<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('design_jobs', 'sync_origin')) {
                $table->enum('sync_origin', ['manual', 'automatic'])->default('manual')->after('source_type');
                $table->timestamp('auto_synced_at')->nullable()->after('sync_origin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('design_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('design_jobs', 'auto_synced_at')) {
                $table->dropColumn('auto_synced_at');
            }
            if (Schema::hasColumn('design_jobs', 'sync_origin')) {
                $table->dropColumn('sync_origin');
            }
        });
    }
};
