<?php

namespace App\Modules\Finance\Governance;

use App\Models\User;
use App\Modules\Finance\Models\PaymentSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Carries an activated decision into the record the existing code already reads
 * (Report 75). Called only from GovernanceService::activate(), inside its
 * transaction, so a decision and its effect commit together or not at all.
 *
 *  - a setting becomes a finance_settings row, approved and effective-dated, which
 *    is exactly what FinanceSetting::approvedValue() has always looked for;
 *  - a bank account, M-Pesa or the card becomes the state of its paying account;
 *  - the WIP policy and account mappings are read live through GovernanceRuntime,
 *    and classifications and tax verifications are records of a decision, so none
 *    of those writes anything here.
 *
 * Nothing is created that did not exist: no chart account, and no paying account
 * other than the M-Pesa and Card rows the reference data already defines.
 */
class GovernanceApplier
{
    public function apply(array $item, FinanceConfigVersion $version, User $actor): void
    {
        $value = (array) $version->value;

        match ($item['applies']) {
            'finance_setting' => $this->setting($item, $version, $value, $actor),
            'float_limit' => DB::table('payment_sources')->where('code', $item['source']['code'])
                ->update(['float_limit' => ($value['not_applicable'] ?? false) ? null : $value['amount'], 'updated_at' => now()]),
            'payment_source' => match ($item['type']) {
                'bank' => $this->source($item['source']['code'], (bool) $value['active'], $value['account_code']),
                'mpesa' => $this->channel('MPESA', $value, ($value['mode'] ?? null) === 'held_balance' ? $value['account_code'] : null),
                'card' => $this->channel('CARD', $value, null),
            },
            default => null,
        };
    }

    private function setting(array $item, FinanceConfigVersion $version, array $value, User $actor): void
    {
        $key = $item['setting'];
        $from = $version->in_force_from->toDateString();
        $latest = DB::table('finance_settings')->where('key', $key)->orderByDesc('effective_from')->first();

        // The row in force until now ends the day before; its figure and who approved it stay as they were.
        DB::table('finance_settings')->where('key', $key)->whereDate('effective_from', '<', $from)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
            ->update(['effective_to' => $version->in_force_from->copy()->subDay()->toDateString(), 'updated_at' => now()]);

        $row = [
            'value' => json_encode(($value['not_applicable'] ?? false) ? null : $value['amount']),
            'label' => $latest->label ?? $item['title'], 'description' => $latest->description ?? $item['question'],
            'approved_by' => $version->decided_by, 'approved_at' => $version->decided_at, 'effective_to' => null, 'updated_at' => now(),
        ];
        // A row seeded for the very same day is replaced by the approved one rather than duplicated.
        DB::table('finance_settings')->updateOrInsert(['key' => $key, 'effective_from' => $from], $row + ['created_at' => now()]);
    }

    private function source(string $code, bool $active, ?string $accountCode): void
    {
        $source = PaymentSource::query()->where('code', $code)->lockForUpdate()->first()
            ?? throw ValidationException::withMessages(['item' => "Paying account {$code} no longer exists."]);
        $accountId = $accountCode === null ? null : DB::table('chart_of_accounts')->where('code', $accountCode)->value('id');
        if ($active && $accountId === null) {
            throw ValidationException::withMessages(['value.account_code' => "{$accountCode} is not in the chart of accounts, so this cannot be switched on."]);
        }
        $source->forceFill(['is_active' => $active, 'gl_account_id' => $accountId])->save();
    }

    /** M-Pesa and the card: off, or on against the account the decision names. */
    private function channel(string $code, array $value, ?string $ownAccount): void
    {
        $source = PaymentSource::query()->where('code', $code)->lockForUpdate()->first();
        if (! ($value['in_use'] ?? false)) {
            // "WNG does not use it": switched off and unlinked. Nothing to do if it never existed.
            $source?->forceFill(['is_active' => false, 'gl_account_id' => null])->save();

            return;
        }
        $accountId = $ownAccount !== null
            ? DB::table('chart_of_accounts')->where('code', $ownAccount)->value('id')
            : PaymentSource::query()->where('code', $value['settlement_source'])->value('gl_account_id');
        if ($accountId === null) {
            throw ValidationException::withMessages(['value' => 'The account this settles to is no longer available. Return the proposal for correction.']);
        }
        $source ??= new PaymentSource(['code' => $code, 'type' => $code === 'MPESA' ? 'mobile_money' : 'card',
            'name' => $code === 'MPESA' ? 'Company M-Pesa' : 'Company Card', 'currency' => 'KES']);
        if ($code === 'CARD' && filled($value['label'] ?? null)) {
            $source->name = $value['label'];
        }
        $source->forceFill(['is_active' => true, 'gl_account_id' => $accountId])->save();
    }
}
