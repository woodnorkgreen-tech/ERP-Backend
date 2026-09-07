<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops `journey_purpose` from the three travel categories that ask for it.
 *
 * Every requisition already carries a required Purpose, and the form's own
 * placeholder for it reads "crew transport for the site installation". The
 * migration that introduced these categories then gave Crew transport, Truck &
 * vehicle hire and Office & admin transport a second required question asking
 * for the same sentence — not by design, but because the extra questions were
 * carried across verbatim from the superseded folk categories rather than
 * re-read against what the form already asks on its own.
 *
 * So a requester on one of those three types writes the journey out twice and
 * an approver reads it twice. `travel_date` stays: the date of the journey is a
 * fact Purpose does not carry.
 *
 * Requisitions already raised keep their answer. `journey_purpose` sits in
 * their own `custom_fields` beside a frozen `type_snapshot` that still lists
 * the field, and both the show and edit screens read a historical requisition
 * from that snapshot in preference to the live type.
 */
return new class extends Migration
{
    private const CODES = ['tl_crw_001', 'tl_hir_001', 'oe_trp_001'];

    private const FIELD = ['key' => 'journey_purpose', 'type' => 'text', 'required' => true];

    public function up(): void
    {
        $this->rewrite(fn (array $fields) => array_values(array_filter(
            $fields,
            fn ($field) => ! is_array($field) || ($field['key'] ?? null) !== self::FIELD['key'],
        )));
    }

    public function down(): void
    {
        $this->rewrite(function (array $fields) {
            foreach ($fields as $field) {
                if (is_array($field) && ($field['key'] ?? null) === self::FIELD['key']) {
                    return $fields;
                }
            }

            $fields[] = self::FIELD;

            return $fields;
        });
    }

    /**
     * Rewrites each row from what that row actually holds, rather than writing
     * one canonical field list over all three. A question an administrator has
     * since added to one of these types survives: this removes a single field,
     * it does not reset the form back to what the seed migration wrote.
     */
    private function rewrite(callable $transform): void
    {
        $rows = DB::table('petty_cash_requisition_types')
            ->whereIn('code', self::CODES)
            ->get(['id', 'request_fields']);

        foreach ($rows as $row) {
            $fields = json_decode($row->request_fields ?? '[]', true);

            if (! is_array($fields)) {
                continue;
            }

            $next = $transform($fields);

            if ($next === $fields) {
                continue;
            }

            DB::table('petty_cash_requisition_types')
                ->where('id', $row->id)
                ->update([
                    'request_fields' => $next ? json_encode($next) : null,
                    'updated_at' => now(),
                ]);
        }
    }
};
