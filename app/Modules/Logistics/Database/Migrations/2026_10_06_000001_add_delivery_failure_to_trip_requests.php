<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a driver can't complete a delivery, the trip is flagged rather than
 * silently stuck "on the way": the Logistics lead then chooses between
 * sending it back to dispatch or cancelling it. Kept as plain columns (no
 * new status value) so the existing status enum and every screen that
 * filters by it are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            $table->timestamp('delivery_failed_at')->nullable();
            $table->text('delivery_failure_reason')->nullable();
            // null = awaiting the lead's decision; otherwise redispatched | cancelled
            $table->string('failure_resolution', 20)->nullable();
            $table->timestamp('failure_resolved_at')->nullable();
            $table->unsignedBigInteger('failure_resolved_by_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_failed_at', 'delivery_failure_reason',
                'failure_resolution', 'failure_resolved_at', 'failure_resolved_by_id',
            ]);
        });
    }
};
