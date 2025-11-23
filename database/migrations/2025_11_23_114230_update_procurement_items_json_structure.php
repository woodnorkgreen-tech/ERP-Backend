<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Iterate through all records and update the JSON structure
        $records = DB::table('task_procurement_data')->get();

        foreach ($records as $record) {
            $items = json_decode($record->procurement_items, true);

            if (!is_array($items)) {
                continue;
            }

            $updatedItems = array_map(function ($item) {
                // Add new fields if they don't exist
                if (!isset($item['stock_status'])) {
                    // Map old availability_status to new fields
                    $oldStatus = $item['availability_status'] ?? 'pending';
                    
                    if ($oldStatus === 'available') {
                        $item['stock_status'] = 'in_stock';
                        $item['procurement_status'] = 'not_needed';
                        $item['stock_quantity'] = $item['quantity'] ?? 0;
                        $item['purchase_quantity'] = 0;
                    } else {
                        $item['stock_status'] = 'pending_check'; // Default for others
                        $item['stock_quantity'] = 0;
                        $item['purchase_quantity'] = $item['quantity'] ?? 0;
                        
                        // Map other statuses
                        if ($oldStatus === 'ordered') {
                            $item['procurement_status'] = 'ordered';
                        } elseif ($oldStatus === 'received') {
                            $item['procurement_status'] = 'received';
                        } elseif ($oldStatus === 'cancelled') {
                            $item['procurement_status'] = 'cancelled';
                        } else {
                            $item['procurement_status'] = 'pending';
                        }
                    }
                }
                
                // Ensure all new fields are present
                $item['stock_status'] = $item['stock_status'] ?? 'pending_check';
                $item['stock_quantity'] = $item['stock_quantity'] ?? 0;
                $item['procurement_status'] = $item['procurement_status'] ?? 'pending';
                $item['purchase_quantity'] = $item['purchase_quantity'] ?? 0;
                $item['purchase_order_number'] = $item['purchase_order_number'] ?? null;
                $item['expected_delivery_date'] = $item['expected_delivery_date'] ?? null;

                return $item;
            }, $items);

            DB::table('task_procurement_data')
                ->where('id', $record->id)
                ->update(['procurement_items' => json_encode($updatedItems)]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // We can optionally revert the JSON structure, but it's usually not strictly necessary for JSON additions.
        // If needed, we would remove the new keys.
    }
};
