<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasFactory;

    protected $table = 'ledger_entries';

    protected $fillable = [
        'employee_id',
        'technical_labour_id',
        'kind',
        'hours',
        'balance_after',
        'ot_entry_id',
        'compensation_id',
        'source_type',
        'source_snapshot',
        'chain_hash',
        'note',
        'occurred_at',
    ];

    protected $casts = [
        'source_snapshot' => 'json',
        'occurred_at' => 'datetime',
        'hours' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function technicalLabour(): BelongsTo
    {
        return $this->belongsTo(TechnicalLabour::class);
    }

    public function otEntry(): BelongsTo
    {
        return $this->belongsTo(OTEntry::class, 'ot_entry_id');
    }

    public function compensation(): BelongsTo
    {
        return $this->belongsTo(Compensation::class, 'compensation_id');
    }

    /**
     * Generate a chain hash for the ledger entry.
     * This hashes the current content plus the previous entry's hash.
     */
    public static function generateHash(LedgerEntry $entry, ?string $previousHash): string
    {
        $data = [
            'employee_id' => $entry->employee_id,
            'technical_labour_id' => $entry->technical_labour_id,
            'kind' => $entry->kind,
            'hours' => (string) $entry->hours,
            'balance_after' => (string) $entry->balance_after,
            'occurred_at' => $entry->occurred_at->toIso8601String(),
            'previous_hash' => $previousHash,
        ];
        
        ksort($data);
        $payload = json_encode($data);

        return hash('sha256', $payload);
    }
}
