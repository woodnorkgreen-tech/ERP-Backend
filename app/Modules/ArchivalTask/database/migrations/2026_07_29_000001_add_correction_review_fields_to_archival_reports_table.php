<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archival_reports', function (Blueprint $table) {
            $table->foreignId('correction_requested_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('correction_requested_at')->nullable()->after('correction_requested_by');
            $table->text('correction_notes')->nullable()->after('correction_requested_at');
            $table->timestamp('correction_resolved_at')->nullable()->after('correction_notes');
            $table->unsignedSmallInteger('revision_number')->default(0)->after('correction_resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('archival_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('correction_requested_by');
            $table->dropColumn([
                'correction_requested_at',
                'correction_notes',
                'correction_resolved_at',
                'revision_number',
            ]);
        });
    }
};
