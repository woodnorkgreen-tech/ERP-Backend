<?php

namespace App\Modules\Finance\Support;

/**
 * How money reaches the payee. One list, for every payment in the ERP.
 *
 * A method is NOT an account. The list this replaces conflated the two — it
 * offered `equity`, `stanbic`, `ncba`, `kcb` and `family` alongside `cash` and
 * `mpesa`, so "which bank" and "how it was sent" were the same answer and only
 * one of them could be recorded. Which account the money left is
 * `payment_source_id`; that is the only place a bank is named.
 *
 * Three further copies of this list existed and disagreed with each other: the
 * `payment_methods` table (which additionally held three account rows), the
 * `Rule::in` on CreateDisbursementRequest, and a free string on spend_vouchers.
 * Anything that validates, labels or offers a method reads it from here.
 */
final class PaymentMethods
{
    /** code => human label. Order is the order they are offered in. */
    public const ALL = [
        'cash' => 'Cash',
        'mpesa' => 'M-Pesa',
        'bank_transfer' => 'Bank Transfer',
        'cheque' => 'Cheque',
        'rtgs' => 'RTGS',
        'eft' => 'EFT',
        'card' => 'Card',
    ];

    /** Methods that move money without a reference to quote back. */
    public const WITHOUT_REFERENCE = ['cash'];

    /**
     * Methods a given kind of paying account can plausibly transmit through.
     *
     * Advisory, not a constraint: it seeds the form's shortlist so the common
     * case is one click, while any method remains selectable. A float pays a
     * driver in cash and reimburses a supplier by M-Pesa from the same tin, so
     * refusing the unusual combination would only teach people to lie about it.
     */
    public const TYPICAL_FOR_SOURCE_TYPE = [
        'petty_cash' => ['cash', 'mpesa'],
        'bank' => ['bank_transfer', 'cheque', 'rtgs', 'eft'],
        'mobile_money' => ['mpesa'],
        'card' => ['card'],
        'payable' => [],
    ];

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_keys(self::ALL);
    }

    public static function label(string $code): string
    {
        return self::ALL[$code] ?? ucfirst(str_replace('_', ' ', $code));
    }

    public static function requiresReference(?string $code): bool
    {
        return $code !== null && ! in_array($code, self::WITHOUT_REFERENCE, true);
    }

    /**
     * Shape the API returns and the frontend renders from.
     *
     * `requires_reference` travels with the option so a form knows to demand an
     * external reference without hardcoding which methods need one.
     *
     * @return array<int, array{value: string, label: string, requires_reference: bool, typical_for: array<int, string>}>
     */
    public static function options(): array
    {
        $typicalFor = [];
        foreach (self::TYPICAL_FOR_SOURCE_TYPE as $sourceType => $codes) {
            foreach ($codes as $code) {
                $typicalFor[$code][] = $sourceType;
            }
        }

        return array_map(
            fn (string $code) => [
                'value' => $code,
                'label' => self::ALL[$code],
                'requires_reference' => self::requiresReference($code),
                'typical_for' => $typicalFor[$code] ?? [],
            ],
            self::values(),
        );
    }
}
