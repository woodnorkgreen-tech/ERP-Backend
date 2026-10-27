<?php

namespace App\Modules\MaterialsLibrary\Services;

use App\Models\ElementMaterial;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Report 79B — the one catalogue search.
 *
 * Every picker used to call `LibraryMaterial::scopeSearch`, a single
 * `LIKE '%whole phrase%'`. "18mm mdf" found nothing because the catalogue says
 * "MDF 18mm", "tape masking" found nothing, and one typo found nothing. On dev
 * it matched 34 of the 620 descriptions BOM authors typed by hand, so people
 * gave up and typed free text.
 *
 * This ranks a caller's own query (its filters, its permissions) by how well
 * each item matches what was typed, and says why. It never decides identity:
 * every result is a suggestion a person confirms.
 *
 * Tiers:
 *  - exact     code typed in full, or the same words as the name in any order,
 *              with no size or spec difference;
 *  - likely    every typed word found (prefix, small typo, plural, alias), or
 *              the same description was linked to this item before;
 *  - ambiguous only some words found, close spelling, or the sizes differ.
 *
 * Words are split with MaterialDuplicateFinder::tokens(), so search and the
 * duplicate check read text the same way.
 */
class MaterialSearch
{
    public const TIER_EXACT = 'exact';
    public const TIER_LIKELY = 'likely';
    public const TIER_AMBIGUOUS = 'ambiguous';

    /**
     * Shorthand people type for the word the catalogue uses. Token level only,
     * so it can never turn one material into another; it only lets a search
     * find the item.
     */
    public const ALIASES = [
        'ply' => 'plywood',
        'galv' => 'galvanised',
        'galvanized' => 'galvanised',
        'alu' => 'aluminium',
        'aluminum' => 'aluminium',
        'color' => 'colour',
        'gray' => 'grey',
    ];

    /** Above this, candidates are pre-filtered in SQL instead of scored in full. */
    private const FULL_SCAN_LIMIT = 5000;

    public function __construct(private readonly MaterialDuplicateFinder $finder) {}

    /**
     * Rank the materials the caller's query allows.
     *
     * @param  Builder  $base  the caller's query on library_materials (filters, scopes)
     * @return array<int, array{tier: string, reason: string, score: float, spec_differs: bool}> keyed by id, best first
     */
    public function rank(Builder $base, string $term, int $limit = 50, bool $withHistory = false): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }

        $pool = (clone $base)->reorder()->setEagerLoads([]);
        if ((clone $pool)->count() > self::FULL_SCAN_LIMIT) {
            $pool->where(function ($query) use ($term) {
                foreach ($this->finder->tokens($term) as $token) {
                    $stem = mb_substr($token, 0, 3);
                    $query->orWhere('library_materials.material_name', 'like', "%{$stem}%")
                        ->orWhere('library_materials.material_code', 'like', "%{$token}%");
                }
            });
        }

        // select() rather than get($columns): a caller's withCount() has already
        // set the column list, and get() would keep it.
        $rows = $pool->select([
            'library_materials.id', 'library_materials.material_name', 'library_materials.material_code',
            'library_materials.alternative_item_name',
        ])->get()->map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => (string) $row->material_name,
            'code' => (string) $row->material_code,
            'aliases' => (string) ($row->alternative_item_name ?? ''),
        ])->all();

        $history = $withHistory ? $this->history($term) : [];

        return $this->score($term, $rows, $history, $limit);
    }

    /**
     * Narrow the caller's query to the ranked matches, best first.
     *
     * Endpoints keep their own filters, eager loads and pagination; only which
     * rows match, and in what order, comes from here. Pass $order = false when
     * the caller has an explicit sort the person chose.
     *
     * @return array<int, array{tier: string, reason: string, score: float, spec_differs: bool}>
     */
    public function apply(Builder $query, string $term, int $limit = 500, bool $order = true, bool $withHistory = false): array
    {
        $ranked = $this->rank($query, $term, $limit, $withHistory);
        $ids = array_map('intval', array_keys($ranked));

        $query->whereIn('library_materials.id', $ids ?: [0]);
        if ($order && $ids !== []) {
            $query->reorder()->orderByRaw('FIELD(library_materials.id, '.implode(',', $ids).')');
        }

        return $ranked;
    }

    /**
     * Pure scoring, separate from the database so it can be tested on its own.
     *
     * @param  array<int, array{id: int, name: string, code?: string, aliases?: string}>  $rows
     * @param  array<int, int>  $history  times each material id was linked to this exact description before
     * @return array<int, array{tier: string, reason: string, score: float, spec_differs: bool}>
     */
    public function score(string $term, array $rows, array $history = [], int $limit = 50): array
    {
        $typed = $this->words($term);
        if ($typed === []) {
            return [];
        }
        $typedNumbers = $this->numbers($typed);
        $typedKey = $this->key($typed);
        $termLower = mb_strtolower(trim($term));

        $results = [];
        foreach ($rows as $row) {
            $code = mb_strtolower(trim((string) ($row['code'] ?? '')));
            $name = $this->words((string) $row['name']);
            $aliasWords = $this->words((string) ($row['aliases'] ?? ''));
            $vocabulary = array_values(array_unique(array_merge($name, $aliasWords)));
            $numbers = $this->numbers($name);
            $specDiffers = $typedNumbers !== [] && $numbers !== [] && array_diff($typedNumbers, $numbers) !== [];
            $times = (int) ($history[$row['id']] ?? 0);

            if ($code !== '' && $code === $termLower) {
                $results[$row['id']] = $this->hit(self::TIER_EXACT, 'Material code', 1000, false);
                continue;
            }

            // Same words, any order.
            if ($this->key($name) === $typedKey || ($aliasWords !== [] && $this->key($aliasWords) === $typedKey)) {
                $results[$row['id']] = $this->hit(self::TIER_EXACT, 'Same name', 900 + min($times, 50), false);
                continue;
            }

            [$coverage, $fuzzy] = $this->coverage($typed, $vocabulary);
            $codePrefix = $code !== '' && mb_strlen($termLower) >= 3 && str_starts_with($code, $termLower);

            if ($times > 0 && ! $specDiffers) {
                $results[$row['id']] = $this->hit(self::TIER_LIKELY, "Linked {$times}× before for this description", 800 + min($times, 50), false);
                continue;
            }
            if ($codePrefix) {
                $results[$row['id']] = $this->hit(self::TIER_LIKELY, 'Code starts with this', 700, false);
                continue;
            }

            if ($coverage >= 0.999) {
                // Extra catalogue words lower the score slightly, so the
                // shortest complete match ("MDF 18mm Standard") outranks a
                // longer one that merely contains the same words.
                $precision = count($typed) / max(count($name), 1);
                $score = ($fuzzy ? 520 : 600) + 50 * min($precision, 1);
                $results[$row['id']] = $specDiffers
                    ? $this->hit(self::TIER_AMBIGUOUS, 'Different size or specification', 300 + 50 * min($precision, 1), true)
                    : $this->hit(self::TIER_LIKELY, $fuzzy ? 'Close spelling' : 'All words match', $score, false);
                continue;
            }

            similar_text(implode(' ', $typed), implode(' ', $name), $percent);
            if ($coverage >= 0.5 || $percent >= 80) {
                $results[$row['id']] = $this->hit(
                    self::TIER_AMBIGUOUS,
                    $specDiffers ? 'Different size or specification' : 'Some words match',
                    200 + 100 * max($coverage, $percent / 100),
                    $specDiffers,
                );
            }
        }

        uasort($results, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit, true);
    }

    /**
     * The overall verdict for a result list: whether the person is looking at
     * one clear answer, several, or none.
     *
     * @param  array<int, array{tier: string, score: float}>  $ranked
     */
    public function verdict(array $ranked): string
    {
        $top = array_values($ranked);
        if ($top === []) {
            return 'none';
        }
        $strong = array_filter($top, fn ($r) => $r['tier'] !== self::TIER_AMBIGUOUS);
        if ($strong === []) {
            return self::TIER_AMBIGUOUS;
        }
        $first = reset($strong);
        $close = array_filter($strong, fn ($r) => $first['score'] - $r['score'] < 5);

        return count($close) > 1 ? self::TIER_AMBIGUOUS : $first['tier'];
    }

    /**
     * How many times each item was confirmed for this exact description on a
     * BOM line before. Report 78B's Resolve already treats this as the
     * strongest suggestion; search now does too.
     *
     * @return array<int, int>
     */
    public function history(string $term): array
    {
        $key = mb_strtolower(trim($term));

        return ElementMaterial::query()
            ->whereNotNull('library_material_id')
            ->whereRaw('LOWER(TRIM(description)) = ?', [$key])
            ->select('library_material_id', DB::raw('COUNT(*) AS times'))
            ->groupBy('library_material_id')
            ->pluck('times', 'library_material_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return array{0: float, 1: bool} share of typed words found, and whether any needed a fuzzy match */
    private function coverage(array $typed, array $vocabulary): array
    {
        $found = 0.0;
        $fuzzy = false;
        foreach ($typed as $word) {
            $best = 0.0;
            foreach ($vocabulary as $candidate) {
                $match = $this->wordMatch($word, $candidate);
                if ($match > $best) {
                    $best = $match;
                }
                if ($best >= 1.0) {
                    break;
                }
            }
            if ($best > 0 && $best < 1.0) {
                $fuzzy = true;
            }
            $found += $best > 0 ? 1 : 0;
        }

        return [$found / count($typed), $fuzzy];
    }

    /** 1 = same word, 0.9 = prefix, 0.75 = close spelling, 0 = different. Numbers must be equal. */
    private function wordMatch(string $typed, string $candidate): float
    {
        if ($typed === $candidate) {
            return 1.0;
        }
        if ($this->isNumber($typed) || $this->isNumber($candidate)) {
            return $this->isNumber($typed) && $this->isNumber($candidate) && (float) $typed === (float) $candidate ? 1.0 : 0.0;
        }
        if (mb_strlen($typed) >= 2 && str_starts_with($candidate, $typed)) {
            return 0.9;
        }
        $length = min(mb_strlen($typed), mb_strlen($candidate));
        $allowed = $length >= 7 ? 2 : ($length >= 4 ? 1 : 0);

        return $allowed > 0 && levenshtein($typed, $candidate) <= $allowed ? 0.75 : 0.0;
    }

    /** Lower-case words, singular, aliases applied, unit noise kept as its own word. */
    private function words(string $text): array
    {
        return array_values(array_map(function (string $token) {
            $token = self::ALIASES[$token] ?? $token;

            return $this->isNumber($token) ? $token : $this->singular($token);
        }, $this->finder->tokens($text)));
    }

    private function singular(string $word): string
    {
        if (mb_strlen($word) <= 3 || str_ends_with($word, 'ss')) {
            return $word;
        }
        if (preg_match('/(ch|sh|x|z)es$/u', $word)) {
            return mb_substr($word, 0, -2);
        }

        return str_ends_with($word, 's') ? mb_substr($word, 0, -1) : $word;
    }

    /**
     * Report 79C: the identity of a name, as search reads it — its words in
     * any order, singular, aliases applied, numbers kept. Two names with the
     * same key say the same thing; one different word or size is a different
     * key. The duplicate gate uses this so search and registration agree.
     */
    public function identityKey(string $name): string
    {
        return $this->key($this->words($name));
    }

    private function key(array $words): string
    {
        sort($words);

        return implode(' ', $words);
    }

    private function numbers(array $words): array
    {
        $numbers = array_values(array_map(fn ($n) => (string) (float) $n, array_filter($words, fn ($w) => $this->isNumber($w))));
        sort($numbers);

        return $numbers;
    }

    private function isNumber(string $word): bool
    {
        return (bool) preg_match('/^\d+(\.\d+)?$/', $word);
    }

    private function hit(string $tier, string $reason, float $score, bool $specDiffers): array
    {
        return ['tier' => $tier, 'reason' => $reason, 'score' => round($score, 1), 'spec_differs' => $specDiffers];
    }
}
