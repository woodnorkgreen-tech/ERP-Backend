<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->timestamp('stop_required_at')->nullable()->after('completed_at');
            $table->text('stop_required_reason')->nullable()->after('stop_required_at');
            $table->foreignId('stop_required_by')->nullable()->after('stop_required_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('stop_acknowledged_at')->nullable()->after('stop_required_by');
            $table->foreignId('stop_acknowledged_by')->nullable()->after('stop_acknowledged_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stop_acknowledged_by');
            $table->dropColumn('stop_acknowledged_at');
            $table->dropConstrainedForeignId('stop_required_by');
            $table->dropColumn(['stop_required_at', 'stop_required_reason']);
        });
    }
};
