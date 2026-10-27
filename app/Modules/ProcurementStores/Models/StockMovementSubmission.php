<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Report 79E: the record that a given "Confirm" (user + client request id)
 * already moved stock, and what it answered. See StockMovementController.
 */
class StockMovementSubmission extends Model
{
    protected $fillable = [
        'user_id', 'client_request_id', 'movement_type', 'payload_hash', 'batch_number', 'log_ids', 'response',
    ];

    protected $casts = [
        'log_ids' => 'array',
        'response' => 'array',
    ];

    /**
     * The same request, whatever order its keys arrived in. Quantities are
     * compared as numbers so "5" and 5 are the same request.
     */
    public static function hashOf(string $type, array $lines): string
    {
        $normalise = function ($value) use (&$normalise) {
            if (is_array($value)) {
                if (! array_is_list($value)) {
                    ksort($value);
                }

                return array_map($normalise, $value);
            }

            return is_numeric($value) ? (string) (0 + $value) : $value;
        };

        return hash('sha256', json_encode(['type' => $type, 'lines' => $normalise($lines)]));
    }
}
