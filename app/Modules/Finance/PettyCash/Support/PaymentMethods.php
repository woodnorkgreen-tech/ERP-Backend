<?php

namespace App\Modules\Finance\PettyCash\Support;

/**
 * The payment methods a petty cash movement can use.
 *
 * One source. The list previously existed in at least four places — the column
 * enum, an inline map in the top-up controller, a stale `Rule::in` in the unused
 * FormRequests, and two label maps on the frontend — and they had already
 * drifted: the FormRequest copy predated the bank methods, so wiring it as
 * written would have rejected equity, stanbic, ncba, kcb and family outright.
 *
 * Anything that needs to validate, label, or offer a payment method reads it
 * from here, and the API exposes it so the frontend does not keep its own copy.
 */
final class PaymentMethods
{
    /** value => human label. Order is the order they are offered in. */
    public const ALL = [
        'cash' => 'Cash',
        'mpesa' => 'M-Pesa',
        'equity' => 'Equity',
        'stanbic' => 'Stanbic',
        'ncba' => 'NCBA',
        'kcb' => 'KCB',
        'family' => 'Family Bank',
        'bank_transfer' => 'Bank Transfer',
        'other' => 'Other',
    ];

    /** Methods that move money without a reference to quote back. */
    public const WITHOUT_REFERENCE = ['cash'];

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_keys(self::ALL);
    }

    public static function label(string $value): string
    {
        return self::ALL[$value] ?? ucfirst(str_replace('_', ' ', $value));
    }

    /**
     * Shape the API returns and the frontend renders from.
     *
     * `requires_reference` travels with the option so the form knows to demand a
     * transaction code without hardcoding which methods need one.
     *
     * @return array<int, array{value: string, label: string, requires_reference: bool}>
     */
    public static function options(): array
    {
        return array_map(
            fn (string $value) => [
                'value' => $value,
                'label' => self::ALL[$value],
                'requires_reference' => ! in_array($value, self::WITHOUT_REFERENCE, true),
            ],
            self::values(),
        );
    }
}
