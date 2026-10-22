<?php

namespace App\Modules\ProcurementStores\Services;

use App\Models\ElementMaterial;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use Illuminate\Validation\ValidationException;

/**
 * Report 78B — the one reading of a BOM requirement's unit in stock units.
 *
 * A BOM line states its unit as free text ("Sheets", "sqm", "Mtrs"). Stock,
 * issues, valuation and cost are counted in the material's base unit. Before
 * this class every caller compared the BOM number with a stock number as if
 * the two units were the same, so a 12 sqm requirement allowed 12 sheets out.
 *
 * The rule, in order:
 *  1. Spelling variants of the same unit are aliases (Mtrs = m, Kgs = kg,
 *     sqm = m2). Factor 1, quantity unchanged.
 *  2. Fixed metric relations inside one dimension (mm → m, g → kg, ml → l)
 *     use their standard factor.
 *  3. Anything else — area to sheets, rolls to metres, packets to kilograms —
 *     is material-specific. It is only accepted when the material carries an
 *     approved conversion from the BOM unit to its stock unit in the Material
 *     Catalogue (material_uom_conversions, maintained by the library custodian).
 *  4. Otherwise the requirement is incompatible: it cannot be resolved to that
 *     material, and nothing can be issued against it.
 *
 * The BOM quantity and unit are never rewritten. The approved factor and its
 * basis are captured on the line at resolution (requirement_* columns) so a
 * later catalogue edit cannot silently change what was approved.
 */
class RequirementUnits
{
    /** Spelling variants only. Never two different units. */
    public const ALIASES = [
        'pcs' => ['pcs', 'pc', 'piece', 'pieces'],
        'sheet' => ['sheet', 'sheets'],
        'roll' => ['roll', 'rolls'],
        'box' => ['box', 'boxes'],
        'set' => ['set', 'sets'],
        'm' => ['m', 'mtr', 'mtrs', 'metre', 'meter', 'metres', 'meters'],
        'mm' => ['mm', 'millimetre', 'millimetres', 'millimeter', 'millimeters'],
        'm2' => ['m2', 'sqm', 'sq m', 'sq.m', 'm²', 'square metre', 'square metres', 'square meter', 'square meters'],
        'l' => ['l', 'ltr', 'ltrs', 'litre', 'litres', 'liter', 'liters'],
        'ml' => ['ml', 'millilitre', 'millilitres', 'milliliter', 'milliliters'],
        'kg' => ['kg', 'kgs', 'kilogram', 'kilograms'],
        'g' => ['g', 'gram', 'grams'],
        'pack' => ['pack', 'packs'],
        'can' => ['can', 'cans'],
        'bottle' => ['bottle', 'bottles'],
        'cartridge' => ['cartridge', 'cartridges'],
        'cyl' => ['cyl', 'cylinder', 'cylinders'],
        'pkts' => ['pkts', 'pkt', 'packet', 'packets'],
    ];

    /** Fixed relations within one dimension: from => [to => factor]. */
    public const STANDARD_FACTORS = [
        'mm' => ['m' => '0.001'],
        'm' => ['mm' => '1000'],
        'g' => ['kg' => '0.001'],
        'kg' => ['g' => '1000'],
        'ml' => ['l' => '0.001'],
        'l' => ['ml' => '1000'],
    ];

    /** @var array<string, UnitOfMeasure>|null lower-case text => unit */
    private ?array $lookup = null;

    public function normalise(?string $text): ?UnitOfMeasure
    {
        $key = mb_strtolower(trim((string) $text));
        if ($key === '') {
            return null;
        }

        return $this->lookup()[$key] ?? null;
    }

    public function baseUnitOf(LibraryMaterial $material): ?UnitOfMeasure
    {
        if ($material->base_uom_id) {
            $unit = collect($this->lookup())->first(fn (UnitOfMeasure $uom) => (int) $uom->id === (int) $material->base_uom_id);
            if ($unit) {
                return $unit;
            }
        }

        return $this->normalise($material->unit_of_measure);
    }

    /**
     * How this requirement reads in the material's stock unit.
     *
     * @return array{status: string, factor: ?string, basis: ?string, requirement_unit: ?string, requirement_unit_text: string, base_unit: ?string, conversion_id: ?int, stored: bool, reason: ?string}
     */
    public function assess(ElementMaterial $line, ?LibraryMaterial $material = null): array
    {
        $material ??= $line->library_material_id ? $line->libraryMaterial : null;
        $text = (string) $line->unit_of_measurement;
        $required = $this->normalise($text);
        $result = [
            'status' => 'unlinked', 'factor' => null, 'basis' => null,
            'requirement_unit' => $required?->code, 'requirement_unit_text' => $text,
            'base_unit' => null, 'conversion_id' => null, 'stored' => false, 'reason' => null,
        ];

        if (! $material) {
            return $result;
        }

        $base = $this->baseUnitOf($material);
        $result['base_unit'] = $base?->code;

        // An approved reading captured at resolution wins while it still
        // describes the same unit, the same material and the same stock unit.
        $stored = $line->requirement_unit_basis ?? [];
        if ($line->requirement_base_factor !== null
            && (int) ($stored['material_id'] ?? 0) === (int) $material->id
            && (int) $line->requirement_uom_id === (int) $required?->id
            && (int) $line->requirement_base_uom_id === (int) $base?->id) {
            return array_merge($result, [
                'status' => 'compatible', 'factor' => $this->decimal($line->requirement_base_factor),
                'basis' => $stored['basis'] ?? 'stored', 'conversion_id' => $stored['conversion_id'] ?? null, 'stored' => true,
            ]);
        }

        if (! $required) {
            return array_merge($result, ['status' => 'incompatible',
                'reason' => "The BOM unit '{$text}' is not a recognised unit of measure, so it cannot be read in stock units. Correct the unit on the material list."]);
        }
        if (! $base) {
            return array_merge($result, ['status' => 'incompatible',
                'reason' => "'{$material->material_name}' has no stock unit in the Material Catalogue. Set its base unit first."]);
        }

        if ((int) $required->id === (int) $base->id) {
            return array_merge($result, ['status' => 'compatible', 'factor' => '1.000000',
                'basis' => mb_strtolower(trim($text)) === mb_strtolower($base->code) ? 'same_unit' : 'alias']);
        }

        if (isset(self::STANDARD_FACTORS[$required->code][$base->code])) {
            return array_merge($result, ['status' => 'compatible',
                'factor' => $this->decimal(self::STANDARD_FACTORS[$required->code][$base->code]), 'basis' => 'standard']);
        }

        $conversion = $material->relationLoaded('uomConversions')
            ? $material->uomConversions->first(fn ($row) => (int) $row->from_uom_id === (int) $required->id && (int) $row->to_uom_id === (int) $base->id)
            : $material->uomConversions()->where('from_uom_id', $required->id)->where('to_uom_id', $base->id)->first();
        if ($conversion && (float) $conversion->factor > 0) {
            return array_merge($result, ['status' => 'compatible', 'factor' => $this->decimal($conversion->factor),
                'basis' => 'material_conversion', 'conversion_id' => (int) $conversion->id]);
        }

        $reason = $required->dimension === $base->dimension
            ? "'{$material->material_name}' is stocked in {$base->name} and has no conversion from {$required->name}."
            : "The requirement is in {$required->name} ({$required->dimension}) but '{$material->material_name}' is stocked in {$base->name} ({$base->dimension}).";

        return array_merge($result, ['status' => 'incompatible',
            'reason' => $reason.' Add an approved conversion from '.$required->code.' to '.$base->code
                .' on the material in the Material Catalogue (for example the sheet size or roll length), or choose a material stocked in '.$required->name.'.']);
    }

    /** Requirement in stock units, or null when it cannot be read. */
    public function requiredInBase(ElementMaterial $line, ?LibraryMaterial $material = null): ?string
    {
        $assessment = $this->assess($line, $material);

        return $assessment['status'] === 'compatible'
            ? bcmul($this->decimal($line->getRawOriginal('quantity') ?? $line->quantity), $assessment['factor'], 6)
            : null;
    }

    /** Requirement in stock units, refusing the caller's action when it cannot be read. */
    public function requiredInBaseOrFail(ElementMaterial $line, ?LibraryMaterial $material = null, string $field = 'project_material_id'): string
    {
        $assessment = $this->assess($line, $material);
        if ($assessment['status'] !== 'compatible') {
            throw ValidationException::withMessages([$field => "{$line->description}: ".($assessment['reason'] ?? 'the requirement has no Material Library identity yet.')]);
        }

        return bcmul($this->decimal($line->getRawOriginal('quantity') ?? $line->quantity), $assessment['factor'], 6);
    }

    /**
     * The columns that record an approved reading on the line. Returned rather
     * than saved, so the caller writes them inside its own lock.
     *
     * @return array<string, mixed>
     */
    public function snapshot(ElementMaterial $line, LibraryMaterial $material, int $userId): array
    {
        $assessment = $this->assess($line->replicate()->forceFill([
            'requirement_base_factor' => null, 'requirement_unit_basis' => null,
        ]), $material);

        if ($assessment['status'] !== 'compatible') {
            throw ValidationException::withMessages(['material_id' => $assessment['reason']]);
        }

        $base = $this->baseUnitOf($material);

        return [
            'requirement_uom_id' => $this->normalise($line->unit_of_measurement)?->id,
            'requirement_base_uom_id' => $base?->id,
            'requirement_base_factor' => $assessment['factor'],
            'requirement_unit_basis' => [
                'basis' => $assessment['basis'],
                'material_id' => (int) $material->id,
                'conversion_id' => $assessment['conversion_id'],
                'from' => $assessment['requirement_unit'],
                'from_text' => $assessment['requirement_unit_text'],
                'to' => $assessment['base_unit'],
                'factor' => $assessment['factor'],
                'approved_by' => $userId,
                'approved_at' => now()->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, null> */
    public static function clearedSnapshot(): array
    {
        return ['requirement_uom_id' => null, 'requirement_base_uom_id' => null,
            'requirement_base_factor' => null, 'requirement_unit_basis' => null];
    }

    private function lookup(): array
    {
        if ($this->lookup !== null) {
            return $this->lookup;
        }

        $units = UnitOfMeasure::query()->get();
        $map = [];
        foreach ($units as $unit) {
            $map[mb_strtolower($unit->code)] = $unit;
            $map[mb_strtolower($unit->name)] ??= $unit;
        }
        foreach (self::ALIASES as $code => $aliases) {
            $unit = $units->first(fn (UnitOfMeasure $uom) => mb_strtolower($uom->code) === $code);
            if (! $unit) {
                continue;
            }
            foreach ($aliases as $alias) {
                $map[$alias] = $unit;
            }
        }

        return $this->lookup = $map;
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }
}
