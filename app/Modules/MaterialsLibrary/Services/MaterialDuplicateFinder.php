<?php

namespace App\Modules\MaterialsLibrary\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use Illuminate\Support\Collection;

/**
 * Report 78B — existing catalogue items a proposed new material may duplicate.
 *
 * Exact: the same words once case, punctuation and order are ignored
 * ("MDF Board 18mm" = "18mm mdf board"). A request is refused outright.
 * Near: most of the descriptive words are shared, or the names are very
 * close in spelling. The requester must look at them and acknowledge each
 * before a new item is created. `spec_differs` flags candidates whose numeric
 * tokens differ (18mm vs 9mm) — similar names, different materials — so the
 * screen can say so instead of implying a match.
 *
 * Active and Under Review items are both candidates: an item already waiting
 * for the custodian is just as much a duplicate as one already in use.
 */
class MaterialDuplicateFinder
{
    /**
     * @return Collection<int, array{id: int, name: string, code: ?string, status: ?string, category: ?string, base_unit: ?string, match: string, spec_differs: bool, score: float}>
     */
    public function candidates(string $name, ?int $categoryId = null, int $limit = 10): Collection
    {
        $tokens = $this->tokens($name);
        $words = array_values(array_filter($tokens, fn ($t) => ! preg_match('/\d/', $t) && mb_strlen($t) >= 3));
        if ($tokens === []) {
            return collect();
        }

        $pool = LibraryMaterial::query()
            ->with('baseUom:id,code')
            ->whereIn('item_status', ['Active', 'Under Review'])
            ->where(function ($query) use ($words, $tokens) {
                foreach (($words ?: $tokens) as $token) {
                    $query->orWhere('material_name', 'like', '%'.$token.'%');
                }
            })
            ->limit(400)
            ->get(['id', 'material_name', 'material_code', 'item_status', 'category', 'material_category_id', 'base_uom_id', 'unit_of_measure']);

        $normalised = $this->normalise($name);
        $sortedKey = $this->sortedKey($tokens);
        $numbers = $this->numbers($tokens);

        return $pool->map(function (LibraryMaterial $material) use ($normalised, $sortedKey, $words, $numbers, $categoryId) {
            $theirTokens = $this->tokens($material->material_name);
            $theirWords = array_values(array_filter($theirTokens, fn ($t) => ! preg_match('/\d/', $t) && mb_strlen($t) >= 3));
            $theirNumbers = $this->numbers($theirTokens);

            $exact = $this->normalise($material->material_name) === $normalised || $this->sortedKey($theirTokens) === $sortedKey;
            $shared = count(array_intersect($words, $theirWords));
            $wordRatio = min(count($words), count($theirWords)) > 0 ? $shared / min(count($words), count($theirWords)) : 0;
            similar_text($normalised, $this->normalise($material->material_name), $percent);
            $score = max($wordRatio * 100, $percent) + ($categoryId && (int) $material->material_category_id === $categoryId ? 5 : 0);

            if (! $exact && $wordRatio < 0.6 && $percent < 80) {
                return null;
            }

            return [
                'id' => (int) $material->id,
                'name' => $material->material_name,
                'code' => $material->material_code,
                'status' => $material->item_status,
                'category' => $material->category,
                'base_unit' => $material->baseUom?->code ?? $material->unit_of_measure,
                'match' => $exact ? 'exact' : 'near',
                'spec_differs' => $numbers !== [] && $theirNumbers !== [] && $numbers !== $theirNumbers,
                'score' => round($score, 1),
            ];
        })->filter()->sortByDesc(fn ($c) => [$c['match'] === 'exact' ? 1 : 0, $c['score']])->take($limit)->values();
    }

    /** @return array<int, string> */
    public function tokens(string $value): array
    {
        $value = mb_strtolower($value);
        // Keep "3.5x55mm" readable as numbers: split letters from digits.
        $value = preg_replace('/(\d)([a-z])/u', '$1 $2', $value);
        $value = preg_replace('/([a-z])(\d)/u', '$1 $2', $value);
        $value = preg_replace('/[^\p{L}\p{N}.]+/u', ' ', $value);

        return array_values(array_filter(array_map(fn ($t) => trim($t, '.'), explode(' ', $value)), fn ($t) => $t !== ''));
    }

    private function normalise(string $value): string
    {
        return implode(' ', $this->tokens($value));
    }

    private function sortedKey(array $tokens): string
    {
        sort($tokens);

        return implode(' ', $tokens);
    }

    /** @return array<int, string> */
    private function numbers(array $tokens): array
    {
        $numbers = array_values(array_filter($tokens, fn ($t) => (bool) preg_match('/^\d+(\.\d+)?$/', $t)));
        sort($numbers);

        return $numbers;
    }
}
