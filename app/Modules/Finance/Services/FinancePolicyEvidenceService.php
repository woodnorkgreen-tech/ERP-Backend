<?php

namespace App\Modules\Finance\Services;

use App\Models\User;

/** Read-only evidence from the existing governance register and effective Finance settings. */
class FinancePolicyEvidenceService
{
    public function decisions(array $titles): array
    {
        $reference = 'docs/finance-redesign/phase-2/03_WNG_FINANCE_DECISION_REGISTER.md';
        $file = base_path($reference);
        $text = is_file($file) ? file_get_contents($file) : '';
        return collect($titles)->map(function ($label, $key) use ($reference, $text) {
            $row = collect(explode("\n", $text))->first(fn ($line) => str_starts_with($line, '| '.$key.' |'));
            $cells = $row ? array_map('trim', explode('|', trim($row, '|'))) : [];
            $status = $cells ? preg_replace('/\*+/', '', $cells[count($cells) - 1]) : null;
            return [
                'key' => $key, 'label' => $label, 'state' => 'POLICY_REQUIRED',
                'decision_status' => $status ?: 'No authoritative decision record available',
                'authority' => $cells ? $cells[count($cells) - 2] : 'Not recorded',
                'approved_by' => null, 'approved_at' => null,
                'reference' => $row ? $reference.'#'.$key : null,
                'workflows' => str_starts_with($key, 'W') ? [explode('-', $key)[0]] : [$label],
                'message' => $row ? 'Existing Decision Register evidence. A status note is not a signed production approval.'
                    : 'Read-only carryforward. There is no structured decision approval record for this gate; no approval is inferred.',
            ];
        })->values()->all();
    }

    public function settings(array $settings): array
    {
        $people = User::query()->whereIn('id', collect($settings)->pluck('approved_by_id')->filter())->pluck('name', 'id');
        return array_map(function ($setting) use ($people) {
            $id = $setting['approved_by_id'];
            $setting['approved_by'] = $id ? ['id' => $id, 'name' => $people[$id] ?? 'Former or unavailable approver'] : null;
            unset($setting['approved_by_id']);
            $key = $setting['key'];
            $setting['workflows'] = str_starts_with($key, 'petty_cash_') ? ['W3', 'W5']
                : (str_starts_with($key, 'purchase_order_') ? ['W2']
                    : (str_starts_with($key, 'spend_voucher_') ? ['W4'] : (str_contains($key, 'vat') ? ['Tax'] : ['Finance'])));
            return $setting;
        }, $settings);
    }
}
