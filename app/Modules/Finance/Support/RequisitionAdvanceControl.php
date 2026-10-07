<?php

namespace App\Modules\Finance\Support;

use App\Modules\Finance\Governance\GovernanceRuntime;
use App\Modules\Finance\Models\ChartOfAccount;

/**
 * Report 75R-B: which account holds money advanced through a requisition.
 *
 * The answer is an accounting decision, taken per kind of receiver in Finance
 * Setup (Report 75). While it has not been taken, the ledger keeps its existing
 * behaviour — Staff Advances for everyone — and this class reports that as what
 * it is: existing and unapproved, never as an accountant's decision.
 */
final class RequisitionAdvanceControl
{
    public const APPROVED = 'approved';
    public const EXISTING_UNAPPROVED = 'existing_unapproved';

    public static function item(string $receiverType): string
    {
        return 'policy.requisition_advance.'.(in_array($receiverType, ['employee', 'supplier', 'other'], true) ? $receiverType : 'other');
    }

    /**
     * @return array{account_code: string, basis: string, account_id: ?int, account_name: ?string}
     */
    public static function for(?string $receiverType): array
    {
        $approved = $receiverType ? (GovernanceRuntime::instance()->value(self::item($receiverType))['account_code'] ?? null) : null;
        $code = $approved ?: ChartAccountMap::local(FinanceAccountFunctions::STAFF_ADVANCES);
        $account = ChartOfAccount::postable()->where('code', $code)->first(['id', 'name']);

        return [
            'account_code' => (string) $code,
            'basis' => $approved ? self::APPROVED : self::EXISTING_UNAPPROVED,
            'account_id' => $account?->id,
            'account_name' => $account?->name,
        ];
    }
}
