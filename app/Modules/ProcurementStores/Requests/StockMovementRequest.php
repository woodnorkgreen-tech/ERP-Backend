<?php

namespace App\Modules\ProcurementStores\Requests;

use App\Modules\ProcurementStores\Services\StockMovementPoster;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The shape of a stock movement, whatever screen sent it and however many
 * lines it carries.
 *
 * Six endpoints used to validate this, each with its own copy of the rules and
 * its own idea of which fields existed — the batch pair simply did not accept a
 * lot number, an expiry date or a serial, so the same material could be
 * received two ways with two different levels of rigour. The rules live here
 * once; StockMovementPoster owns the ones that need the material itself.
 */
class StockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasAnyRole(['Stores', 'Manager', 'Super Admin']);
    }

    /**
     * A single line is accepted as well as a list, because the older endpoints
     * post one movement at the top level and they route through here too.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('lines') && $this->has('material_id')) {
            $this->merge(['lines' => [$this->except(['type', 'logged_at', 'warehouse_code'])]]);
        }
    }

    public function rules(): array
    {
        $type = (string) $this->input('type');

        $common = [
            'type' => ['required', Rule::in(StockMovementPoster::TYPES)],
            'logged_at' => 'nullable|date',
            'warehouse_code' => 'nullable|string|max:20',
            'lines' => 'required|array|min:1|max:100',
            'lines.*.material_id' => 'required|exists:library_materials,id',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.entered_uom_id' => 'nullable|integer|exists:units_of_measure,id',
            'lines.*.notes' => 'nullable|string',
            'lines.*.project_material_id' => 'nullable|exists:element_materials,id',
        ];

        return array_merge($common, match ($type) {
            'receive' => [
                'lines.*.location' => 'nullable|string|max:50',
                'lines.*.reference_no' => 'nullable|string',
                'lines.*.receipt_unit_cost' => 'nullable|numeric|min:0',
                'lines.*.lot_number' => 'nullable|string|max:100',
                'lines.*.expiry_date' => 'nullable|date|after_or_equal:today',
                'lines.*.serial_numbers' => 'nullable|array',
                'lines.*.serial_numbers.*' => 'string|max:150',
                'lines.*.grn_item_id' => 'nullable|integer|exists:goods_receipt_note_items,id',
                'lines.*.length' => 'nullable|numeric|min:0',
                'lines.*.width' => 'nullable|numeric|min:0',
                'lines.*.thickness' => 'nullable|numeric|min:0',
            ],
            'issue' => [
                'lines.*.project_id' => 'nullable|exists:projects,id',
                // Custody is the point of an issue: an issue nobody is named on
                // is stock that has left the shelf with no one accountable for
                // it. Batch issuing always demanded this and single issuing
                // never did, which is the same split the poster exists to end.
                'lines.*.recipient_name' => 'required|string|max:150',
                'lines.*.reference_no' => 'nullable|string',
                'lines.*.inventory_lot_id' => 'nullable|exists:inventory_lots,id',
                'lines.*.serial_item_ids' => 'nullable|array',
                'lines.*.serial_item_ids.*' => 'integer|exists:inventory_serial_items,id',
            ],
            'return' => [
                'lines.*.original_issue_log_id' => 'required|exists:inventory_logs,id',
                'lines.*.inventory_lot_id' => 'nullable|exists:inventory_lots,id',
                'lines.*.serial_item_ids' => 'nullable|array',
                'lines.*.serial_item_ids.*' => 'integer|exists:inventory_serial_items,id',
            ],
            // A write-off with no account of what happened is an unexplained
            // hole in the balance, so the note is required here and only here.
            'damage' => [
                'lines.*.notes' => 'required|string|min:5',
                'lines.*.reference_no' => 'nullable|string',
                'lines.*.inventory_lot_id' => 'nullable|exists:inventory_lots,id',
                'lines.*.serial_item_ids' => 'nullable|array',
                'lines.*.serial_item_ids.*' => 'integer|exists:inventory_serial_items,id',
            ],
            default => [],
        });
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Add at least one material before posting.',
            'lines.*.material_id.required' => 'Every line needs a material.',
            'lines.*.quantity.min' => 'Every line needs a quantity greater than zero.',
            'lines.*.notes.required' => 'Say what happened — a write-off needs a reason.',
            'lines.*.recipient_name.required' => 'Name who is receiving the stock.',
        ];
    }

    /** The movement's own fields, with the envelope applied to every line. */
    public function lines(): array
    {
        $shared = array_filter([
            'logged_at' => $this->input('logged_at'),
            'warehouse_code' => $this->input('warehouse_code') ?: 'MAIN',
        ], fn ($value) => $value !== null);

        return array_map(fn (array $line) => array_merge($line, $shared), $this->input('lines'));
    }

    public function movementType(): string
    {
        return (string) $this->input('type');
    }
}
