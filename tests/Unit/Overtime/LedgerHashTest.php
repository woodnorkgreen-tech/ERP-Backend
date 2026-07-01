<?php

namespace Tests\Unit\Overtime;

use App\Modules\HR\Models\LedgerEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The overtime ledger is tamper-evident: each entry's chain_hash is derived from its own
 * material fields plus the previous entry's hash. These tests pin that contract so a
 * refactor of generateHash() can't silently weaken the chain.
 */
class LedgerHashTest extends TestCase
{
    /** Build an unsaved ledger entry with sensible, overridable defaults. */
    private function entry(array $overrides = []): LedgerEntry
    {
        return new LedgerEntry(array_merge([
            'employee_id'         => 1,
            'technical_labour_id' => null,
            'kind'                => 'credit',
            'hours'               => 3,
            'balance_after'       => 3,
            'occurred_at'         => '2026-06-22T18:00:00+00:00',
        ], $overrides));
    }

    public function test_hash_is_deterministic_for_identical_input(): void
    {
        $a = LedgerEntry::generateHash($this->entry(), 'PREV');
        $b = LedgerEntry::generateHash($this->entry(), 'PREV');

        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a), 'Expected a SHA-256 hex digest.');
    }

    public function test_hash_changes_when_the_previous_hash_changes(): void
    {
        // This is what links the chain: re-pointing an entry at a different parent
        // must produce a different hash, so a re-ordered/forked chain is detectable.
        $this->assertNotSame(
            LedgerEntry::generateHash($this->entry(), 'PARENT_A'),
            LedgerEntry::generateHash($this->entry(), 'PARENT_B'),
        );

        // The genesis case (no parent) must also differ from a child of some parent.
        $this->assertNotSame(
            LedgerEntry::generateHash($this->entry(), null),
            LedgerEntry::generateHash($this->entry(), 'PARENT_A'),
        );
    }

    /**
     * Every field the hash is supposed to commit to must actually change the digest.
     */
    #[DataProvider('materialFieldProvider')]
    public function test_hash_changes_when_a_material_field_changes(array $override): void
    {
        $base = LedgerEntry::generateHash($this->entry(), 'PREV');
        $mutated = LedgerEntry::generateHash($this->entry($override), 'PREV');

        $this->assertNotSame($base, $mutated, 'Mutating ' . key($override) . ' should change the hash.');
    }

    public static function materialFieldProvider(): array
    {
        return [
            'employee_id'         => [['employee_id' => 2]],
            'technical_labour_id' => [['employee_id' => null, 'technical_labour_id' => 5]],
            'kind'                => [['kind' => 'debit']],
            'hours'               => [['hours' => 4]],
            'balance_after'       => [['balance_after' => 7]],
            'occurred_at'         => [['occurred_at' => '2026-06-23T18:00:00+00:00']],
        ];
    }
}
