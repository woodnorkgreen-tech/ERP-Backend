<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which expense types can be bought on a purchase order.
 *
 * `job_id_rule` says whether a code may carry a job number. Nothing said whether
 * a code describes a *purchase* at all, so the procurement requisition's
 * classification column offered the whole catalogue and a requester could code a
 * purchase order to "Withholding tax remitted", "Client refund / credit note" or
 * "Material issued from store to project". None of those are things a supplier
 * can be asked to deliver; the last is a stores movement that would double-count
 * against the issue it describes.
 *
 * The complement of this already exists as data — the September 2026 alignment
 * migration picked out the codes "genuinely spent out of the cash tin" for the
 * fund-requisition list, and noted there that equipment hire, subcontractors,
 * professional fees, insurance and venue hire "belong on a purchase order". This
 * column is that sentence made queryable, so procurement can ask for its own
 * slice the same way petty cash asks for its.
 *
 * Default true: the catalogue is overwhelmingly things you buy — 80 of 94 active
 * codes — so the flag names the exceptions rather than restating the rule, and a
 * code Finance adds later is procurable unless they say otherwise.
 */
return new class extends Migration
{
    /**
     * Not purchases, by kind rather than by accounting class — the class is free
     * text and two of these ("Operating expense", "Balance sheet / Not an
     * expense") hold procurable codes as well.
     */
    private const NOT_PROCURABLE = [
        // Cash moving around the business, or out and back again.
        'NE-001',   // Petty-cash float top-up
        'NE-002',   // Transfer between company accounts
        'NE-003',   // Staff advance / imprest issued
        'NE-004',   // Staff advance retired with receipts
        'NE-005',   // Unused advance returned
        'NE-006',   // Supplier deposit / advance — arises from a PO, is not one
        'NE-007',   // Refundable deposit paid

        // Stock already owned, moving between a shelf and a job.
        'NE-009',   // Material issued from store to project
        'NE-010',   // Material returned from project to store

        // Taxes and borrowings, settled with an authority or a lender.
        'NE-013',   // Input VAT recoverable
        'NE-014',   // Withholding tax remitted
        'NE-015',   // VAT or corporation tax paid
        'NE-016',   // Loan principal repayment
        'NE-017',   // Owner drawings / dividend

        // Money owed to or returned to a customer.
        'NE-018',   // Customer advance / deposit received
        'NE-019',   // Client refund / credit note

        // A journal, not a transaction.
        'NE-023',   // Project WIP transfer to cost of sales

        // Posted automatically from the payment itself, never requested —
        // stated as much by the alignment migration that excluded it from the
        // fund-requisition list.
        'OE-FIN-001', // Bank and mobile-money transaction charges
    ];

    public function up(): void
    {
        Schema::table('expense_codes', function (Blueprint $table) {
            $table->boolean('is_procurable')->default(true)->after('is_active')
                ->comment('Can be requisitioned and put on a purchase order.');
        });

        DB::table('expense_codes')
            ->whereIn('code', self::NOT_PROCURABLE)
            ->update(['is_procurable' => false]);
    }

    public function down(): void
    {
        Schema::table('expense_codes', function (Blueprint $table) {
            $table->dropColumn('is_procurable');
        });
    }
};
