<?php

namespace App\Modules\ProcurementStores\Services;

use Illuminate\Validation\ValidationException;

final class StoresDecimal
{
    public static function quantity(mixed $value): string
    {
        if (!preg_match('/^\d{1,14}(?:\.\d{1,6})?$/', (string) $value) || bccomp((string) $value, '0', 6) <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Use a positive decimal quantity with at most six decimal places.']);
        }
        return bcadd((string) $value, '0', 6);
    }
    public static function cost(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!preg_match('/^\d{1,12}(?:\.\d{1,8})?$/', (string) $value)) {
            throw ValidationException::withMessages(['receipt_unit_cost' => 'Use a nonnegative decimal receipt cost.']);
        }
        return bcadd((string) $value, '0', 8);
    }
    public static function money(string $value): string { return bcadd($value, '0.005', 2); }
    public static function sum(iterable $values, int $scale = 6): string
    {
        $total = '0';
        foreach ($values as $value) $total = bcadd($total, (string) $value, $scale);
        return bcadd($total, '0', $scale);
    }
}
