<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * W2 Return for Correction on supplier bills (Report 60 §16).
 *
 * Purchase orders have had return/correct/resubmit since W2-6; supplier bills
 * had none — an incorrect bill could only be deleted and re-keyed, and
 * `PUT /bills/{bill}` was routed to a handler that did not exist. These columns
 * hold the CURRENT review state only, the same shape as purchase_orders and
 * project_invoices; every return and correction is also written to
 * governance_audit_logs, which is the immutable history.
 *
 * Payment state stays in `bills.status`; review state is kept apart from it so
 * the two can never be conflated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->unsignedBigInteger('returned_by')->nullable()->after('verification_notes');
            $table->timestamp('returned_at')->nullable()->after('returned_by');
            $table->text('return_reason')->nullable()->after('returned_at');
            $table->timestamp('resubmitted_at')->nullable()->after('return_reason');

            // Named explicitly: MariaDB caps identifiers at 64 characters.
            $table->foreign('returned_by', 'bills_returned_by_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropForeign('bills_returned_by_fk');
            $table->dropColumn(['returned_by', 'returned_at', 'return_reason', 'resubmitted_at']);
        });
    }
};
