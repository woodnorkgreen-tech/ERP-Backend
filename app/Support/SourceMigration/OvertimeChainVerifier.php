<?php

namespace App\Support\SourceMigration;

use App\Modules\HR\Models\LedgerEntry;

/**
 * Verifies the overtime ledger's tamper-evident hash chain on one connection.
 *
 * The chain is per subject — the employee, or the technical labourer when there is
 * no employee — ordered by occurred_at then id, exactly as OvertimeService finds a
 * chain head. Each entry's stored chain_hash must equal LedgerEntry::generateHash()
 * over the entry and the previous entry's stored hash. Nothing is re-hashed or
 * written: a historical break is reported as it is.
 *
 * Run on the staging copy and on the target: a break present on both is historical;
 * a break only on the target was introduced by the migration.
 */
class OvertimeChainVerifier
{
    public function __construct(private readonly string $connection) {}

    public function verify(int $sampleLimit = 20): array
    {
        $entries = LedgerEntry::on($this->connection)
            ->orderBy('occurred_at')->orderBy('id')
            ->get();

        $previous = [];
        $broken = [];
        $unhashed = 0;
        foreach ($entries as $entry) {
            $subject = $entry->employee_id ? "employee:{$entry->employee_id}" : "technical_labour:{$entry->technical_labour_id}";
            $previousHash = $previous[$subject] ?? null;

            if ($entry->chain_hash === null || $entry->chain_hash === '') {
                $unhashed++;
            } elseif (! hash_equals($entry->chain_hash, LedgerEntry::generateHash($entry, $previousHash))) {
                $broken[] = ['id' => $entry->id, 'subject' => $subject];
            }
            $previous[$subject] = $entry->chain_hash;
        }

        return [
            'connection' => $this->connection,
            'entries' => $entries->count(),
            'subjects' => count($previous),
            'verified' => $entries->count() - count($broken) - $unhashed,
            'unhashed_entries' => $unhashed,
            'broken_links' => count($broken),
            'broken_samples' => array_slice($broken, 0, $sampleLimit),
            'broken_ids' => array_column($broken, 'id'),
            'passes' => $broken === [],
        ];
    }

    /** Breaks on the target that the staging copy does not also have. */
    public static function introducedByMigration(array $staging, array $target): array
    {
        return array_values(array_diff($target['broken_ids'], $staging['broken_ids']));
    }
}
