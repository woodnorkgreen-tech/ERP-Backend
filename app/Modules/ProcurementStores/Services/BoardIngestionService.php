<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\ProcurementStores\Models\Board;

/**
 * Thin façade kept for backward compatibility with the existing ingest endpoint.
 * All logic now lives in BoardRegistrationService.
 */
class BoardIngestionService
{
    public function __construct(private readonly BoardRegistrationService $registration) {}

    /** @return Board[] */
    public function ingestBatch(
        int    $libraryMaterialId,
        int    $quantity,
        string $batchNumber,
        ?int   $length    = null,
        ?int   $width     = null,
        ?int   $thickness = null,
        ?int   $userId    = null,
    ): array {
        return $this->registration->registerBatch(
            material:     $libraryMaterialId,
            quantity:     $quantity,
            batchNumber:  $batchNumber,
            length:       $length,
            width:        $width,
            thickness:    $thickness,
            userId:       $userId,
        );
    }
}
