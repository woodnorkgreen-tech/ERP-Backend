<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GoodsReceiptRecorded
{
    use Dispatchable, SerializesModels;
    public function __construct(public readonly int $goodsReceiptNoteId) {}
}
