<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use DomainException;

/**
 * The governed expense code for a catalogue material.
 *
 * Lifted out of StoresCostProducer so both ends of a material's life read the
 * same rule. A stores issue resolves its code here when the material is
 * consumed; the requisition picker resolves it here when the material is
 * ordered. Two copies of this precedence would let the accrual and the issue
 * classify the same material differently — the same tax treatment reached by
 * two routes and disagreeing.
 *
 * Precedence, most specific first:
 *
 *   1. A code set on the material itself.
 *   2. The material's category, then its parent category, then its legacy
 *      category string, against `cost-collector.material_category_expense_codes`
 *      — one entry per category rather than per catalogue row, which is how
 *      Finance governs 428 materials without touching each one.
 *   3. `cost-collector.default_material_expense_code`, the last resort.
 */
class MaterialExpenseCodeResolver
{
    /**
     * The code for this material, or null when nothing resolves.
     *
     * Used where a missing code is an ordinary answer — a picker suggesting a
     * default has nothing to offer, and says so by leaving the field empty
     * rather than guessing.
     */
    public function resolve(?object $material, bool $allowFallback = true): ?string
    {
        $attributes = $material?->attributes ?? [];
        $attributes = $attributes['attributes'] ?? $attributes;
        $configured = $attributes['expense_code'] ?? $attributes['finance_expense_code'] ?? null;

        if ($configured && ExpenseCode::active()->where('code', $configured)->exists()) {
            return (string) $configured;
        }

        $categoryMap = (array) config('cost-collector.material_category_expense_codes', []);
        $categoryNames = array_values(array_filter([
            $material?->materialCategory?->name,
            $material?->materialCategory?->parent?->name,
            $material?->category,
        ]));

        foreach ($categoryNames as $name) {
            $mapped = $categoryMap[$name] ?? null;
            if ($mapped && ExpenseCode::active()->where('code', $mapped)->exists()) {
                return (string) $mapped;
            }
        }

        // A purchase suggestion must come from an actual mapping, never an
        // unrelated default used to keep historic stock movements posting.
        if (! $allowFallback) {
            return null;
        }

        // DM-WD-001 is *wood*. Using it for every material — adhesives, fabric,
        // fixings, print media — classified spend Finance then had to unpick by
        // hand. It survives only as the last resort, and only because refusing to
        // post would strand real stock movements outside project cost; lines that
        // land on it are marked `unmapped_expense_code` so the gap stays visible
        // rather than being absorbed silently into the wood account.
        $default = (string) config('cost-collector.default_material_expense_code', 'DM-WD-001');

        return ExpenseCode::active()->where('code', $default)->value('code')
            ?? ExpenseCode::active()->where('expense_type', 'like', '%material%')->orderBy('code')->value('code');
    }

    /**
     * As resolve(), but for callers that cannot proceed without an answer.
     *
     * A stores issue is a movement that already happened; refusing to classify
     * it would strand a physical fact outside project cost, so the absence of
     * any configured material code is a configuration error rather than a
     * routine outcome.
     */
    public function resolveOrFail(?object $material): string
    {
        return $this->resolve($material)
            ?? throw new DomainException('No active direct-material expense code is configured for Stores issues.');
    }

    /** True when the material had no governed code and fell through to the default. */
    public function usesDefault(string $resolved): bool
    {
        return $resolved === (string) config('cost-collector.default_material_expense_code', 'DM-WD-001');
    }
}
