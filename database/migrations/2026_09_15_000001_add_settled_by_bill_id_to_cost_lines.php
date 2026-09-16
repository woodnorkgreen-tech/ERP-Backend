<?php

use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes a double-payment defect: a GRN-driven accrual cost line stayed
 * "eligible" for a Payment Voucher forever, even after the Bill that
 * superseded it (three-way match) had moved its liability onto Accounts
 * Payable and been paid in full. Nothing ever told the cost line its accrual
 * had been cleared, so JournalPostingService::resolveVerifiedLiabilityAccount()
 * kept validating it against its own, now-stale journal entry and happily
 * debited 2150 Accrued Expenses a second time. Confirmed to have already
 * happened once: CL-0000022 / BILL-2026-0003 (paid in full 2026-09-12) was
 * paid an extra KES 2,000 via SV-20260913-0000004 the next day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_lines', function (Blueprint $table) {
            $table->foreignId('settled_by_bill_id')->nullable()->after('journal_entry_id')
                ->constrained('bills')->nullOnDelete()
                ->comment('Set when the Bill superseding this GRN accrual is verified; excludes it from Payment Voucher liability settlement.');
        });

        // Backfill: every GRN-accrual cost line whose purchase order already has
        // a verified bill was already superseded before this column existed —
        // including the two live in production when this was found.
        DB::statement('
            UPDATE cost_lines cl
            JOIN purchase_order_items poi
              ON poi.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(cl.details, "$.purchase_order_item_id")) AS UNSIGNED)
            JOIN bills b ON b.purchase_order_id = poi.purchase_order_id
            SET cl.settled_by_bill_id = b.id
            WHERE cl.source_type = ?
              AND cl.source_ref = "accrual"
              AND b.verified_at IS NOT NULL
              AND cl.settled_by_bill_id IS NULL
        ', [GoodsReceiptNoteItem::class]);
    }

    public function down(): void
    {
        Schema::table('cost_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settled_by_bill_id');
        });
    }
};
