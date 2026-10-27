<?php

namespace Tests\Unit\MaterialsLibrary;

use App\Modules\MaterialsLibrary\Services\MaterialDuplicateFinder;
use App\Modules\MaterialsLibrary\Services\MaterialSearch;
use PHPUnit\Framework\TestCase;

/**
 * Report 79B — the catalogue search ranks; it never decides identity.
 * Pure scoring only: no database.
 */
class MaterialSearchTest extends TestCase
{
    private MaterialSearch $search;

    private array $catalogue = [
        ['id' => 1, 'name' => 'MDF 18mm Standard', 'code' => 'MDF-18'],
        ['id' => 2, 'name' => 'MDF 9mm Sheet', 'code' => 'MDF-09'],
        ['id' => 3, 'name' => 'Plywood 18mm Hardwood', 'code' => 'PLY-18'],
        ['id' => 4, 'name' => 'Masking Tape 24mm', 'code' => 'TAPE-24'],
        ['id' => 5, 'name' => 'Squegees', 'code' => 'SQG-01'],
        ['id' => 6, 'name' => 'Mdf Screws 40mm', 'code' => 'SCR-40'],
        ['id' => 7, 'name' => 'Acrylic Matt Wall Paint - White', 'code' => 'PNT-W'],
        ['id' => 8, 'name' => 'Galvanised Wire', 'code' => 'WIRE-G'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->search = new MaterialSearch(new MaterialDuplicateFinder);
    }

    private function top(string $term, array $history = []): array
    {
        $ranked = $this->search->score($term, $this->catalogue, $history);
        $id = array_key_first($ranked);

        return [$id, $ranked[$id] ?? null, $ranked];
    }

    public function test_word_order_does_not_matter(): void
    {
        [$id, $hit] = $this->top('18mm mdf standard');

        $this->assertSame(1, $id);
        $this->assertSame(MaterialSearch::TIER_EXACT, $hit['tier']);
    }

    public function test_a_code_typed_in_full_is_an_exact_match(): void
    {
        [$id, $hit] = $this->top('ply-18');

        $this->assertSame(3, $id);
        $this->assertSame('Material code', $hit['reason']);
    }

    public function test_a_typo_is_a_likely_match_not_an_exact_one(): void
    {
        [$id, $hit] = $this->top('plywod 18mm');

        $this->assertSame(3, $id);
        $this->assertSame(MaterialSearch::TIER_LIKELY, $hit['tier']);
        $this->assertSame('Close spelling', $hit['reason']);
    }

    public function test_singular_and_plural_read_as_the_same_word(): void
    {
        [$id, $hit] = $this->top('squegee');

        $this->assertSame(5, $id);
        $this->assertSame(MaterialSearch::TIER_EXACT, $hit['tier']);
    }

    public function test_a_different_size_is_never_an_exact_or_likely_match(): void
    {
        $ranked = $this->search->score('mdf 9mm sheet', $this->catalogue);

        $this->assertSame(2, array_key_first($ranked));
        $this->assertArrayHasKey(1, $ranked);
        $this->assertSame(MaterialSearch::TIER_AMBIGUOUS, $ranked[1]['tier']);
        $this->assertTrue($ranked[1]['spec_differs']);
    }

    public function test_a_different_pack_or_length_is_flagged(): void
    {
        $ranked = $this->search->score('mdf screws 30mm', $this->catalogue);

        $this->assertSame(MaterialSearch::TIER_AMBIGUOUS, $ranked[6]['tier']);
        $this->assertTrue($ranked[6]['spec_differs']);
    }

    public function test_spelling_variants_are_folded(): void
    {
        [$id, $hit] = $this->top('galvanized wire');

        $this->assertSame(8, $id);
        $this->assertSame(MaterialSearch::TIER_EXACT, $hit['tier']);
    }

    public function test_a_misspelt_word_still_finds_the_item(): void
    {
        [$id] = $this->top('white pant');

        $this->assertSame(7, $id);
    }

    public function test_an_earlier_confirmed_link_ranks_first(): void
    {
        [$id, $hit] = $this->top('board 18', [3 => 4]);

        $this->assertSame(3, $id);
        $this->assertStringContainsString('Linked 4×', $hit['reason']);
    }

    public function test_unrelated_text_returns_nothing(): void
    {
        $this->assertSame([], $this->search->score('generator', $this->catalogue));
        $this->assertSame([], $this->search->score('', $this->catalogue));
    }

    public function test_the_verdict_is_ambiguous_when_several_items_match_equally(): void
    {
        $twins = [
            ['id' => 1, 'name' => 'Paint Brush 4 inch', 'code' => 'A'],
            ['id' => 2, 'name' => 'Paint Brush 4 inch', 'code' => 'B'],
        ];

        $this->assertSame(MaterialSearch::TIER_AMBIGUOUS, $this->search->verdict($this->search->score('paint brush 4 inch', $twins)));
        $this->assertSame(MaterialSearch::TIER_EXACT, $this->search->verdict($this->search->score('masking tape 24mm', $this->catalogue)));
        $this->assertSame('none', $this->search->verdict([]));
    }
}
