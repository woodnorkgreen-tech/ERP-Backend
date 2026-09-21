<?php

namespace App\Modules\ProcurementStores\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class GoodsReceiptNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'grn_number',
        'date',
        'purchase_order_id',
        'batch_number',
        'store_location',
        'quality_check',
        'store_status',
        'received_by',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /**
     * Get the purchase order associated with this GRN.
     */
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Get the items for this GRN.
     */
    public function items()
    {
        return $this->hasMany(GoodsReceiptNoteItem::class);
    }

    /**
     * Get the user who received the goods.
     */
    public function receivedByUser()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Generate the next GRN number.
     */
    public static function generateGrnNumber()
    {
        $year = date('Y');
        $lastGrn = self::where('grn_number', 'LIKE', "GRN-{$year}-%")
            ->orderBy('grn_number', 'desc')
            ->first();

        if ($lastGrn) {
            $lastNumber = (int) substr($lastGrn->grn_number, -4); // Changed from -3 to -4
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT); // Changed from 3 to 4
        } else {
            $newNumber = '0001'; // Changed from '001' to '0001'
        }

        return "GRN-{$year}-{$newNumber}";
    }

    /**
     * Generate the next batch number.
     */
    public static function generateBatchNumber()
    {
        $year = date('Y');
        $lastBatch = self::where('batch_number', 'LIKE', "BAT-{$year}-%")
            ->orderBy('batch_number', 'desc')
            ->first();

        if ($lastBatch) {
            $lastNumber = (int) substr($lastBatch->batch_number, -4); // Changed from -3 to -4
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT); // Changed from 3 to 4
        } else {
            $newNumber = '0001'; // Changed from '001' to '0001'
        }

        return "BAT-{$year}-{$newNumber}";
    }

    /**
     * Move this GRN's own store_status to 'confirmed' once none of its
     * accepted items are still 'pending'. Three different places can confirm
     * the last item on a note — the Stores confirmation queue, the receiving
     * queue's Check-In, and quality inspection — and the note-level status
     * (what the Deliveries list and the confirmation queue's own filter both
     * read) only ever moves here. Call this after any of them writes an
     * item's store_status, inside the same transaction.
     */
    public function closeOutIfFullyConfirmed(): void
    {
        $stillPending = $this->items()
            ->where('accepted', true)
            ->where('store_status', 'pending')
            ->exists();

        if (! $stillPending) {
            $this->update(['store_status' => 'confirmed']);
        }
    }
}