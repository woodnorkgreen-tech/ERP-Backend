<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PurchaseOrderApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $purchaseOrderId) {}
}
