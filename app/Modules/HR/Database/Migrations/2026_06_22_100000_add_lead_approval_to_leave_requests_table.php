<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('lead_approved_by')->nullable()->after('approved_by');
            $table->timestamp('lead_approved_at')->nullable()->after('lead_approved_by');
            $table->text('lead_review_notes')->nullable()->after('lead_approved_at');

            $table->foreign('lead_approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['lead_approved_by']);
            $table->dropColumn(['lead_approved_by', 'lead_approved_at', 'lead_review_notes']);
        });
    }
};
