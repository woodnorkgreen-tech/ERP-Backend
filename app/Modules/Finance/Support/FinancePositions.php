<?php

namespace App\Modules\Finance\Support;

use App\Models\EnquiryPayment;
use App\Modules\ProcurementStores\Models\Bill;

/**
 * Headline positions shared by a workspace and the Finance Overview (Report 65).
 *
 * Each figure is defined once here and read by both, so the Overview can never
 * show a number its own workspace disagrees with.
 */
final class FinancePositions
{
    /** Supplier bills (Report 60): the Payables workspace's summary. */
    public static function payables(): array
    {
        $today = now()->toDateString();
        $awaiting = 'returned_at is not null and (resubmitted_at is null or returned_at > resubmitted_at)';
        $totals = Bill::query()->whereNotIn('status', ['cancelled'])
            ->selectRaw("sum(case when verified_at is null and status <> 'paid' and not ($awaiting) then 1 else 0 end) as awaiting_verification")
            ->selectRaw("sum(case when verified_at is null and ($awaiting) then 1 else 0 end) as returned_for_correction")
            ->selectRaw("sum(case when verified_at is not null and balance > 0 and status <> 'paid' then 1 else 0 end) as verified_unpaid")
            ->selectRaw("coalesce(sum(case when verified_at is not null and balance > 0 and status <> 'paid' then balance else 0 end),0) as verified_unpaid_amount")
            ->selectRaw("sum(case when due_date < ? and balance > 0 and status <> 'paid' then 1 else 0 end) as overdue", [$today])
            ->selectRaw("coalesce(sum(case when due_date < ? and balance > 0 and status <> 'paid' then balance else 0 end),0) as overdue_amount", [$today])
            ->selectRaw("coalesce(sum(case when balance > 0 and status <> 'paid' then balance else 0 end),0) as outstanding")
            ->first();

        return [
            'awaiting_verification' => (int) ($totals->awaiting_verification ?? 0),
            'returned_for_correction' => (int) ($totals->returned_for_correction ?? 0),
            'verified_unpaid' => (int) ($totals->verified_unpaid ?? 0),
            'verified_unpaid_amount' => self::money($totals->verified_unpaid_amount ?? 0),
            'overdue' => (int) ($totals->overdue ?? 0),
            'overdue_amount' => self::money($totals->overdue_amount ?? 0),
            'outstanding' => self::money($totals->outstanding ?? 0),
        ];
    }

    /** Client receipts (Report 58): awaiting verification, and verified money not yet applied to an invoice. */
    public static function receipts(): array
    {
        $applied = self::APPLIED;
        $totals = EnquiryPayment::query()->whereNull('reversed_at')->where('status', '!=', 'reversed')
            ->selectRaw("sum(case when status = 'pending' then 1 else 0 end) as pending_count")
            ->selectRaw("coalesce(sum(case when status = 'verified' then amount - $applied else 0 end),0) as unapplied_amount")
            ->first();

        return [
            'pending_verification' => (int) ($totals->pending_count ?? 0),
            'unapplied_client_money' => self::money($totals->unapplied_amount ?? 0),
        ];
    }

    /** What a receipt has applied to invoices so far. */
    public const APPLIED = '(select coalesce(sum(a.amount),0) from project_invoice_allocations a where a.enquiry_payment_id = enquiry_payments.id)';

    private static function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
