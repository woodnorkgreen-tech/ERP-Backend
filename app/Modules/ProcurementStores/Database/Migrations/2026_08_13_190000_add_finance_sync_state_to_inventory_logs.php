<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->string('finance_sync_status', 20)->nullable()->after('notes')->index();
            $table->unsignedSmallInteger('finance_sync_attempts')->default(0)->after('finance_sync_status');
            $table->text('finance_sync_error')->nullable()->after('finance_sync_attempts');
            $table->timestamp('finance_synced_at')->nullable()->after('finance_sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropIndex(['finance_sync_status']);
            $table->dropColumn(['finance_sync_status', 'finance_sync_attempts', 'finance_sync_error', 'finance_synced_at']);
        });
    }
};
