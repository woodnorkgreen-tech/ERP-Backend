<?php

namespace App\Modules\MaterialsLibrary\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\MaterialsLibrary\Models\MaterialCategory;
use App\Modules\MaterialsLibrary\Models\UnitOfMeasure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Report 79C — the one duplicate gate every catalogue creation passes.
 *
 * It used to run in a single place (Resolve → Request new). The library form,
 * bulk add, receiving, GRN confirmation and imports created whatever they were
 * sent, so dev already holds 8 groups of same-name items.
 *
 * What counts as "the same material" follows WNG's data, where the
 * specification lives in the name ("MDF 18mm Standard", "Neon - Red"):
 *
 *  - exact: the same words in any order (case, punctuation and plurals
 *    ignored; every number kept), the same stock unit or no unit on one side,
 *    and no conflicting value in a specification the category has configured.
 *    Refused, whatever is acknowledged — use the existing item.
 *  - similar: the duplicate finder's near match, or the same words with a
 *    different stock unit or a conflicting configured specification. Allowed
 *    only once each candidate has been looked at and a reason given.
 *
 * Active and Under Review items both count: an item waiting for the custodian
 * is as much a duplicate as one in use. Stored attributes are compared only
 * for keys the category configures, because older imports shifted columns
 * and the rest cannot be trusted (Report 79C §3).
 */
class MaterialDuplicateGuard
{
    public const MIN_REASON = 10;

    public function __construct(
        private readonly MaterialDuplicateFinder $finder,
        private readonly MaterialSearch $search,
    ) {}

    /**
     * @param  array<string, mixed>  $proposed  material_name, base_uom_id|unit_of_measure, material_category_id, attributes
     * @return array{verdict: string, exact: array<int, array>, similar: array<int, array>}
     */
    public function check(array $proposed, ?int $ignoreId = null): array
    {
        $name = trim((string) ($proposed['material_name'] ?? ''));
        if ($name === '') {
            return ['verdict' => 'new', 'exact' => [], 'similar' => []];
        }

        // The category's default stock unit counts, exactly as registration
        // will apply it, so a pre-check and the save give the same verdict.
        if (blank($proposed['base_uom_id'] ?? null) && blank($proposed['unit_of_measure'] ?? null) && filled($proposed['material_category_id'] ?? null)) {
            $proposed = app(MaterialDefaultsService::class)->apply($proposed);
        }

        $key = $this->search->identityKey($name);
        $unit = $this->unitCode($proposed);
        $categoryId = isset($proposed['material_category_id']) ? (int) $proposed['material_category_id'] : null;
        $specKeys = $this->configuredSpecKeys($categoryId);
        $attributes = $this->attributesOf($proposed['attributes'] ?? []);

        $candidateIds = $this->finder->candidates($name, $categoryId, 25)->pluck('id')->all();
        // The finder pools on words of three letters or more; an item with the
        // same identity but a different spelling of a short word must still
        // be found, so same-key items are looked up directly too.
        $sameKey = LibraryMaterial::query()
            ->whereIn('item_status', ['Active', 'Under Review'])
            ->where('material_name', 'like', '%'.mb_substr($name, 0, 3).'%')
            ->limit(400)
            ->get(['id', 'material_name'])
            ->filter(fn (LibraryMaterial $m) => $this->search->identityKey($m->material_name) === $key)
            ->pluck('id')->all();

        $materials = LibraryMaterial::query()
            ->with(['baseUom:id,code', 'materialCategory:id,name'])
            ->whereIn('id', array_unique(array_merge($candidateIds, $sameKey)))
            ->whereIn('item_status', ['Active', 'Under Review'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get();

        $exact = [];
        $similar = [];
        foreach ($materials as $material) {
            $theirUnit = mb_strtolower((string) ($material->baseUom?->code ?? $material->unit_of_measure ?? ''));
            $sameName = $this->search->identityKey($material->material_name) === $key;
            $unitDiffers = $unit !== '' && $theirUnit !== '' && $unit !== $theirUnit;
            $specConflict = $this->specConflict($specKeys, $attributes, $this->attributesOf($material->attributes ?? []));

            $row = [
                'id' => (int) $material->id,
                'name' => $material->material_name,
                'code' => $material->material_code,
                'status' => $material->item_status,
                'category' => $material->materialCategory?->name ?? $material->category,
                'category_id' => $material->material_category_id ? (int) $material->material_category_id : null,
                'base_unit' => $theirUnit ?: null,
                'match' => 'near',
                'reason' => 'Similar name',
                'spec_differs' => false,
            ];

            if ($sameName && ! $unitDiffers && $specConflict === null) {
                $exact[] = array_merge($row, ['match' => 'exact', 'reason' => 'Same name and stock unit']);
            } elseif ($sameName) {
                $similar[] = array_merge($row, [
                    'reason' => $unitDiffers ? "Same name, stocked in {$theirUnit} not {$unit}" : "Same name, different {$specConflict}",
                    'spec_differs' => true,
                ]);
            } else {
                $similar[] = array_merge($row, ['spec_differs' => $this->numbersDiffer($name, $material->material_name)]);
            }
        }

        return [
            'verdict' => $exact ? 'exact' : ($similar ? 'similar' : 'new'),
            'exact' => $exact,
            'similar' => array_slice($similar, 0, 10),
        ];
    }

    /**
     * Refuse an exact duplicate; refuse a similar one until each candidate is
     * acknowledged and a reason given. Returns the review to store on the item.
     *
     * @param  array{acknowledged?: array<int, int>, reason?: ?string, automatic_reason?: ?string, field?: string}  $review
     * @return array<string, mixed>|null
     */
    public function assertCanRegister(array $proposed, array $review = [], ?int $ignoreId = null, ?string $field = null): ?array
    {
        $field ??= $review['field'] ?? 'material_name';
        $result = $this->check($proposed, $ignoreId);

        if ($result['exact']) {
            $first = $result['exact'][0];
            $this->refuse('DUPLICATE_EXISTS', $field,
                "'{$first['name']}' ({$first['code']}) already exists in the Material Library"
                .($first['status'] === 'Under Review' ? ' and is waiting for the custodian' : '')
                .'. Use it instead of registering a duplicate.',
                $result['exact']);
        }

        // Variants registered together in one bulk save are declared distinct
        // by the person who listed them; only look-alikes that existed before
        // the batch need reviewing.
        $siblings = array_map('intval', $review['siblings'] ?? []);
        $result['similar'] = array_values(array_filter($result['similar'], fn ($c) => ! in_array((int) $c['id'], $siblings, true)));

        if (! $result['similar']) {
            return null;
        }

        $acknowledged = array_map('intval', $review['acknowledged'] ?? []);
        $reason = trim((string) ($review['reason'] ?? $review['automatic_reason'] ?? ''));
        $unseen = array_diff(array_column($result['similar'], 'id'), $acknowledged);
        $automatic = filled($review['automatic_reason'] ?? null);

        if (! $automatic && ($unseen !== [] || mb_strlen($reason) < self::MIN_REASON)) {
            $this->refuse('DUPLICATE_CANDIDATES', 'duplicate_candidates',
                'Similar Material Library items exist. Check each one, and say why this is a different material before registering it.',
                $result['similar']);
        }

        return [
            'reason' => $reason,
            'candidates_reviewed' => array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'reason' => $c['reason']], $result['similar']),
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now()->toIso8601String(),
            'automatic' => $automatic,
        ];
    }

    /**
     * Run $create while no other request can register the same identity.
     *
     * A unique index is not possible while the existing same-name groups
     * remain, so a database advisory lock on the identity key serialises
     * concurrent registrations: the second waits, re-checks, and finds the
     * first. Inside a caller's transaction the lock is held until it commits.
     */
    public function withLock(string $name, callable $create): mixed
    {
        $lock = 'material-identity:'.md5($this->search->identityKey($name));
        $acquired = (int) (DB::selectOne('SELECT GET_LOCK(?, 10) AS l', [$lock])->l ?? 0);
        if ($acquired !== 1) {
            throw ValidationException::withMessages(['material_name' => 'Someone is registering a material with this name right now. Try again in a moment.']);
        }

        $release = fn () => DB::select('SELECT RELEASE_LOCK(?)', [$lock]);
        try {
            $result = $create();
        } catch (\Throwable $exception) {
            $release();
            throw $exception;
        }

        DB::transactionLevel() > 0 ? DB::afterCommit($release) : $release();

        return $result;
    }

    private function refuse(string $code, string $field, string $message, array $candidates): never
    {
        $exception = ValidationException::withMessages([$field => $message]);
        $exception->response = response()->json([
            'message' => $message,
            'code' => $code,
            'candidates' => array_values($candidates),
            'errors' => [$field => [$message]],
        ], 422);

        throw $exception;
    }

    private function unitCode(array $proposed): string
    {
        if (filled($proposed['base_uom_id'] ?? null)) {
            return mb_strtolower((string) UnitOfMeasure::whereKey($proposed['base_uom_id'])->value('code'));
        }

        return mb_strtolower(trim((string) ($proposed['unit_of_measure'] ?? '')));
    }

    /** @return array<int, string> spec keys this category (and its group) configures */
    private function configuredSpecKeys(?int $categoryId): array
    {
        if (! $categoryId) {
            return [];
        }
        $category = MaterialCategory::with('parent')->find($categoryId);

        return collect($category?->resolvedAttributeSchema() ?? [])->pluck('key')->all();
    }

    private function attributesOf(mixed $attributes): array
    {
        $attributes = is_array($attributes) ? $attributes : [];

        return is_array($attributes['attributes'] ?? null) ? $attributes['attributes'] : $attributes;
    }

    /** The label of the first configured spec both sides fill differently, or null. */
    private function specConflict(array $keys, array $ours, array $theirs): ?string
    {
        foreach ($keys as $key) {
            $a = mb_strtolower(trim((string) (is_scalar($ours[$key] ?? null) ? $ours[$key] : '')));
            $b = mb_strtolower(trim((string) (is_scalar($theirs[$key] ?? null) ? $theirs[$key] : '')));
            if ($a !== '' && $b !== '' && $a !== $b) {
                return str_replace('_', ' ', $key);
            }
        }

        return null;
    }

    private function numbersDiffer(string $a, string $b): bool
    {
        $numbers = fn (string $s) => collect($this->finder->tokens($s))->filter(fn ($t) => preg_match('/^\d+(\.\d+)?$/', $t))->map(fn ($n) => (string) (float) $n)->sort()->values()->all();
        $x = $numbers($a);
        $y = $numbers($b);

        return $x !== [] && $y !== [] && $x !== $y;
    }
}
