<?php

namespace App\Modules\Production\Observers;

use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Production\Models\ProductionNcr;

class ProductionNcrObserver
{
    public function created(ProductionNcr $ncr): void
    {
        NotificationService::send(
            type: 'production_ncr_raised',
            title: "NCR {$ncr->ncr_number} raised",
            message: $ncr->description ?: 'A production non-conformance requires review.',
            module: 'production',
            urgency: in_array($ncr->severity, ['critical', 'high'], true) ? 'critical' : 'warning',
            data: ['ncr_id' => $ncr->id, 'url' => "/production/ncrs/{$ncr->id}"],
            users: array_filter([$ncr->owner_user_id]),
            role: ['Super Admin', 'Production Manager', 'Quality Control'],
        );
    }
}
