<?php

namespace App\Modules\ProcurementStores\Services;

use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLot;
use App\Modules\ProcurementStores\Models\InventorySerialItem;
use Illuminate\Support\Str;

class ControlledInventoryService
{
    /** @return array{inventory_lot_id:?int,inventory_serial_item_id:?int,allocations:array} */
    public function apply(LibraryMaterial $material, float $quantity, string $type, array $meta): array
    {
        $lotId = $serialId = null;
        $allocations = [];

        if ($material->is_batch_controlled && (!$material->is_serialized || $type === 'check_in')) {
            $lot = $type === 'check_in'
                ? $this->receiveLot($material, abs($quantity), $meta)
                : $this->moveLot($material, $quantity, $type, $meta);
            $lotId = $lot['first']?->id;
            $allocations = $lot['allocations'];
        }

        if ($material->is_serialized) {
            $serials = $type === 'check_in'
                ? $this->receiveSerials($material, $meta, $lotId)
                : $this->moveSerials($material, $type, $meta);
            $serialId = $serials[0]?->id ?? null;
            if ($material->is_batch_controlled && $type !== 'check_in') {
                $this->moveSerializedLots($serials, $type);
            }
            $allocations = collect($serials)->map(fn ($serial) => [
                'inventory_lot_id' => $serial->inventory_lot_id,
                'inventory_serial_item_id' => $serial->id,
                'quantity' => 1,
            ])->all();
        }

        return ['inventory_lot_id' => $lotId, 'inventory_serial_item_id' => $serialId, 'allocations' => $allocations];
    }

    private function receiveLot(LibraryMaterial $material, float $quantity, array $meta): array
    {
        $lot = InventoryLot::firstOrCreate([
            'material_id' => $material->id,
            'lot_number' => $meta['lot_number'],
            'warehouse_code' => $meta['warehouse_code'] ?? 'MAIN',
            'location_bin' => $meta['location'] ?? 'UNASSIGNED',
        ], ['expiry_date' => $meta['expiry_date'] ?? null, 'status' => 'Released']);
        $lot = InventoryLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
        $lot->quantity_on_hand = (float) $lot->quantity_on_hand + $quantity;
        if (!empty($meta['expiry_date'])) $lot->expiry_date = $meta['expiry_date'];
        $lot->save();
        return ['first' => $lot, 'allocations' => [['inventory_lot_id' => $lot->id, 'inventory_serial_item_id' => null, 'quantity' => $quantity]]];
    }

    private function moveLot(LibraryMaterial $material, float $quantity, string $type, array $meta): array
    {
        if ($type === 'return') {
            $lot = $this->resolveLot($material, $meta);
            if (!$lot) throw new \DomainException('Select the lot being returned.');
            $lot->quantity_on_hand = (float) $lot->quantity_on_hand + abs($quantity);
            $lot->save();
            return ['first' => $lot, 'allocations' => [['inventory_lot_id' => $lot->id, 'inventory_serial_item_id' => null, 'quantity' => abs($quantity)]]];
        }

        $remaining = abs($quantity);
        $first = null;
        $allocations = [];
        $lots = InventoryLot::where('material_id', $material->id)
            ->where('status', 'Released')
            ->whereRaw('(quantity_on_hand - quantity_reserved) > 0')
            ->where(fn ($query) => $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', today()))
            ->when(!empty($meta['inventory_lot_id']), fn ($q) => $q->whereKey($meta['inventory_lot_id']))
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')->orderBy('created_at')->lockForUpdate()->get();
        foreach ($lots as $lot) {
            $first ??= $lot;
            $take = min($remaining, $lot->available);
            $lot->quantity_on_hand = (float) $lot->quantity_on_hand - $take;
            $lot->save();
            $allocations[] = ['inventory_lot_id' => $lot->id, 'inventory_serial_item_id' => null, 'quantity' => $take];
            $remaining -= $take;
            if ($remaining <= 0) break;
        }
        if ($remaining > 0) throw new \DomainException('Insufficient released lot stock for this operation.');
        return ['first' => $first, 'allocations' => $allocations];
    }

    private function resolveLot(LibraryMaterial $material, array $meta): ?InventoryLot
    {
        return InventoryLot::where('material_id', $material->id)
            ->when(!empty($meta['inventory_lot_id']), fn ($q) => $q->whereKey($meta['inventory_lot_id']))
            ->when(empty($meta['inventory_lot_id']) && !empty($meta['lot_number']), fn ($q) => $q->where('lot_number', $meta['lot_number']))
            ->lockForUpdate()->first();
    }

    /** @return array<int,InventorySerialItem> */
    private function receiveSerials(LibraryMaterial $material, array $meta, ?int $lotId): array
    {
        $serialNumbers = $meta['serial_numbers'] ?? [];
        if (InventorySerialItem::where('material_id', $material->id)->whereIn('manufacturer_serial', $serialNumbers)->exists()) {
            throw new \DomainException('One or more manufacturer serial numbers already exist for this material.');
        }
        return collect($serialNumbers)->map(function ($manufacturerSerial) use ($material, $meta, $lotId) {
            return InventorySerialItem::create([
                'material_id' => $material->id,
                'inventory_lot_id' => $lotId,
                'tracking_code' => 'WNG-SER-'.strtoupper(Str::ulid()),
                'manufacturer_serial' => $manufacturerSerial,
                'status' => 'Available',
                'warehouse_code' => $meta['warehouse_code'] ?? 'MAIN',
                'location_bin' => $meta['location'] ?? 'UNASSIGNED',
            ]);
        })->all();
    }

    /** @return array<int,InventorySerialItem> */
    private function moveSerials(LibraryMaterial $material, string $type, array $meta): array
    {
        $ids = $meta['serial_item_ids'] ?? [];
        $requiredStatus = $type === 'return' ? 'Issued' : 'Available';
        $serials = InventorySerialItem::where('material_id', $material->id)
            ->whereIn('id', $ids)->where('status', $requiredStatus)->lockForUpdate()->get();
        if ($serials->count() !== count($ids)) throw new \DomainException('One or more serialized units are invalid.');
        foreach ($serials as $serial) {
            $serial->update(match ($type) {
                'check_out' => ['status' => 'Issued', 'project_id' => $meta['project_id'] ?? null, 'holder_name' => $meta['recipient_name'] ?? null],
                'return' => ['status' => 'Available', 'project_id' => null, 'holder_name' => null],
                'defective' => ['status' => 'Damaged'],
                default => [],
            });
        }
        return $serials->values()->all();
    }

    private function moveSerializedLots(array $serials, string $type): void
    {
        $counts = collect($serials)->filter(fn ($serial) => $serial->inventory_lot_id)
            ->countBy('inventory_lot_id');
        foreach ($counts as $lotId => $count) {
            $lot = InventoryLot::whereKey($lotId)->lockForUpdate()->firstOrFail();
            $next = (float) $lot->quantity_on_hand + ($type === 'return' ? $count : -$count);
            if ($next < 0) throw new \DomainException('The serialized unit lot has insufficient stock.');
            $lot->quantity_on_hand = $next;
            $lot->save();
        }
    }
}
