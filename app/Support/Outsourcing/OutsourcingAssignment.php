<?php

namespace App\Support\Outsourcing;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

/**
 * Outsourcing of design items and print jobs to a partner outside the company.
 *
 * Shared by both modules so the reason list and the rules stay identical.
 * Internal assignee field: 'assigned_to' for design items, 'operator_id' for print jobs.
 */
final class OutsourcingAssignment
{
    public const REASONS = [
        'workload_capacity' => 'Workload / capacity',
        'equipment_down' => 'Printer or equipment down',
        'staff_absence' => 'Staff absence',
        'tight_deadline' => 'Tight deadline',
        'peak_overflow' => 'Overflow / peak period',
        'specialist_skill' => 'Specialist skill needed',
        'material_not_in_stock' => 'Material not in stock',
        'quality_finish' => 'Specific finish or quality',
        'cost_rate' => 'Cost / better rate',
        'client_request' => 'Client request',
        'other' => 'Other',
    ];

    /** Columns cleared when an item is no longer outsourced. */
    public const FIELDS = [
        'outsourced_partner_name',
        'outsourced_reason',
        'outsourced_reason_note',
        'outsourced_by',
        'outsourced_at',
    ];

    /** Request inputs that carry outsourcing intent. */
    private const INPUT_FIELDS = [
        'outsourced',
        'outsourced_partner_name',
        'outsourced_reason',
        'outsourced_reason_note',
    ];

    /** Request rules for the outsourcing fields. Merge into the module's own rules. */
    public static function rules(): array
    {
        return [
            'outsourced' => ['sometimes', 'boolean'],
            'outsourced_partner_name' => ['nullable', 'string', 'max:191'],
            'outsourced_reason' => ['nullable', Rule::in(array_keys(self::REASONS))],
            'outsourced_reason_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Turn outsourcing input into the column values to save.
     *
     * - outsourced = true: partner and reason are required, a note is required for "other",
     *   and the internal assignee is cleared. Assigning both an internal person and an
     *   outside partner is rejected.
     * - outsourced = false: all outsourcing fields are cleared.
     * - outsourced not given: if an internal person is assigned, outsourcing is cleared
     *   (an internal assignment replaces an outside one).
     *
     * @param  array<string, mixed>  $data  Validated request data
     * @param  string  $assigneeField  'assigned_to' or 'operator_id'
     * @param  int|null  $actorId  User recording the change
     * @return array<string, mixed>  The data to save
     */
    public static function apply(array $data, string $assigneeField, ?int $actorId): array
    {
        $outsourced = array_key_exists('outsourced', $data) ? (bool) $data['outsourced'] : null;
        $internalAssignee = $data[$assigneeField] ?? null;

        // The explicit flag is the only way to set outsourcing. Outsourcing fields sent
        // without it are dropped so they cannot be written directly.
        $input = array_intersect_key($data, array_flip(self::INPUT_FIELDS));
        unset($data['outsourced'], $data['outsourced_partner_name'], $data['outsourced_reason'], $data['outsourced_reason_note']);

        if ($outsourced !== true) {
            // Clearing explicitly, or assigning an internal person, removes any outside partner.
            if ($outsourced === false || $internalAssignee !== null) {
                return array_merge($data, self::clearing());
            }

            return $data;
        }

        // array_merge, not +: the required rules below must override the nullable ones.
        $validator = Validator::make($input, array_merge(self::rules(), [
            'outsourced_partner_name' => ['required', 'string', 'max:191'],
            'outsourced_reason' => ['required', Rule::in(array_keys(self::REASONS))],
        ]));

        $validator->sometimes('outsourced_reason_note', ['required', 'string', 'max:2000'], fn ($input) => ($input['outsourced_reason'] ?? null) === 'other');

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        if ($internalAssignee !== null) {
            throw ValidationException::withMessages([
                $assigneeField => ['Choose either an internal assignee or an outside partner, not both.'],
            ]);
        }

        $validated = $validator->validated();

        return array_merge($data, [
            'outsourced_partner_name' => $validated['outsourced_partner_name'],
            'outsourced_reason' => $validated['outsourced_reason'],
            'outsourced_reason_note' => ($validated['outsourced_reason'] === 'other') ? ($validated['outsourced_reason_note'] ?? null) : null,
            'outsourced_by' => $actorId,
            'outsourced_at' => now(),
            $assigneeField => null,
        ]);
    }

    /** Human-readable history line for an outsourcing change. */
    public static function historyNote(?string $partner, ?string $reason, ?string $note): string
    {
        if ($partner === null) {
            return 'Outsourcing cleared; back to an internal assignee.';
        }

        $text = 'Outsourced to '.$partner.' ('.(self::REASONS[$reason] ?? $reason).')';

        return $note ? $text.': '.$note : $text;
    }

    /** Values that clear every outsourcing field. */
    public static function clearing(): array
    {
        return array_fill_keys(self::FIELDS, null);
    }
}
