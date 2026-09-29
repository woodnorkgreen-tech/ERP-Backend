<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\StockCount;
use App\Modules\ProcurementStores\Models\StockCountItem;
use App\Modules\ProcurementStores\Models\StoresFinancePosting;
use App\Modules\ProcurementStores\Services\InventoryValuationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * W5 Finance-facing inventory (Report 61): a READ workspace. Stores owns every
 * stock movement; Finance sees what the stock is worth, whether that agrees
 * with the Inventory control account, what reached projects, and what moved
 * stock without reaching the ledger.
 *
 * Gated on `finance.reports.view` (Accounts): inventory valuation is a
 * Finance report, and seeing the Stores screens does not grant it.
 */
class InventoryFinanceController extends Controller
{
    public function __construct(private InventoryValuationService $valuation)
    {
    }

    /** GET api/finance/inventory/position */
    public function position(Request $request): JsonResponse
    {
        $this->authoriseRead($request);
        $valuation = $this->valuation->valuation();
        $inventoryId = $this->accountId(FinanceAccountFunctions::INVENTORY);
        $gl = $inventoryId ? $this->debitBalance($inventoryId) : null;
        $difference = $gl !== null ? bcsub($valuation['total'], $gl, 2) : null;

        // Received into stock and not yet issued to a job: open GRN accruals.
        // A receipt with no catalogue material (a service or non-stock line
        // forced through a GRN) is never relieved by a Stores issue, so it is
        // reported apart: it sits in the Inventory account but is not stock.
        $accruals = CostLine::query()->where('nature', CostLine::NATURE_ACCRUED)->where('status', CostLine::STATUS_VERIFIED)
            ->where('source_ref', 'accrual')->get(['id', 'amount', 'details', 'settled_by_bill_id']);
        [$stockAccruals, $nonStock] = $accruals->partition(fn (CostLine $l) => filled($l->details['library_material_id'] ?? null));

        $unposted = $this->unpostedAdjustments();
        $openingApproved = StockCount::query()->where('mode', StockCount::MODE_OPENING)->where('status', 'approved')->exists();
        $sync = StoresFinancePosting::query()->whereIn('status', ['failed', 'pending'])->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $issues = CostLine::query()->whereIn('source_ref', ['stock-issue', 'stock-return'])->where('status', CostLine::STATUS_VERIFIED)
            ->selectRaw('count(*) as n, coalesce(sum(amount),0) as total')->first();

        return response()->json(['data' => [
            'valuation' => [
                'method' => 'Moving weighted average',
                'method_note' => 'Each priced receipt re-averages the material\'s unit cost; stock on hand is valued at that average. Boards are valued individually.',
                'material_methods' => $valuation['lines']->pluck('valuation_method')->filter()->unique()->values(),
                'total' => $valuation['total'],
                'stocked_materials' => $valuation['lines']->count(),
            ],
            'reconciliation' => [
                'stores_valuation' => $valuation['total'],
                'inventory_gl' => $gl,
                'inventory_account' => $inventoryId ? $this->account($inventoryId) : null,
                'difference' => $difference,
                'reconciled' => $difference !== null && bccomp($difference, '0.00', 2) === 0,
                // Known contributors, so a difference is explainable rather than hidden.
                'contributors' => [
                    ['key' => 'unposted_adjustments', 'label' => 'Write-offs and counted balances set outside a stock count (no ledger posting)',
                        'count' => $unposted['count'], 'amount' => $unposted['value_at_current_average']],
                    ['key' => 'non_stock_receipts', 'label' => 'Service or non-stock lines received through a GRN (in the Inventory account, not in stock)',
                        'count' => $nonStock->count(), 'amount' => $this->money($nonStock->sum('amount'))],
                    ['key' => 'finance_sync_pending', 'label' => 'Stores movements awaiting their Finance posting',
                        'count' => (int) (($sync['failed'] ?? 0) + ($sync['pending'] ?? 0)), 'amount' => null],
                    // Stock held before the ledger opened reaches the Inventory
                    // account only through an approved opening-inventory count.
                    // Until one is approved that stock is in Stores but not in
                    // the ledger. Reported, never created here (no opening
                    // balances outside the approved count workflow).
                    ['key' => 'opening_inventory_missing', 'label' => 'No opening inventory count approved: stock held before the ledger opened is not in the Inventory account',
                        'count' => $openingApproved ? 0 : 1, 'amount' => null],
                ],
            ],
            'received_not_issued' => [
                'stock' => ['count' => $stockAccruals->count(), 'amount' => $this->money($stockAccruals->sum('amount')),
                    'awaiting_bill' => $this->money($stockAccruals->whereNull('settled_by_bill_id')->sum('amount'))],
                'non_stock' => ['count' => $nonStock->count(), 'amount' => $this->money($nonStock->sum('amount'))],
            ],
            'project_issues' => ['count' => (int) ($issues->n ?? 0), 'net_value' => $this->money($issues->total ?? 0)],
            'adjustments' => $unposted,
            'opening_inventory_approved' => $openingApproved,
            'finance_sync' => ['failed' => (int) ($sync['failed'] ?? 0), 'pending' => (int) ($sync['pending'] ?? 0)],
            'wip_policy' => config('finance_accounts.wip_policy') ?: null,
            'adjustment_account' => $this->account($this->accountId(FinanceAccountFunctions::INVENTORY_ADJUSTMENTS)),
        ]]);
    }

    /** GET api/finance/inventory/issues — project material issues and returns, as costed. */
    public function issues(Request $request): JsonResponse
    {
        $this->authoriseRead($request);
        $filters = $request->validate([
            'enquiry_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $page = CostLine::query()->with(['expenseCode:id,code,simple_meaning,default_debit_account_id', 'expenseCode.debitAccount:id,code,name'])
            ->whereIn('source_ref', ['stock-issue', 'stock-return'])
            ->when($filters['enquiry_id'] ?? null, fn (Builder $q, $id) => $q->where('project_enquiry_id', $id))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $d) => $q->whereDate('incurred_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $d) => $q->whereDate('incurred_at', '<=', $d))
            ->when(trim((string) ($filters['search'] ?? '')), function (Builder $q, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $q->where(fn (Builder $w) => $w->where('job_number', 'like', $like)->orWhere('description', 'like', $like)->orWhere('ref', 'like', $like));
            })
            ->orderByDesc('incurred_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
        $lines = collect($page->items());
        $enquiries = ProjectEnquiry::query()->whereIn('id', $lines->pluck('project_enquiry_id')->filter()->unique() ?: [0])
            ->get(['id', 'job_number', 'title'])->keyBy('id');
        $materials = LibraryMaterial::query()->whereIn('id', $lines->map(fn ($l) => $l->details['library_material_id'] ?? null)->filter()->unique() ?: [0])
            ->pluck('material_name', 'id');

        return response()->json([
            'data' => $lines->map(fn (CostLine $l) => [
                'cost_line' => $l->ref,
                'kind' => $l->source_ref === 'stock-return' ? 'return' : 'issue',
                'project' => ($e = $enquiries->get($l->project_enquiry_id)) ? ['id' => $e->id, 'job_number' => $e->job_number, 'title' => $e->title] : null,
                'material' => ($id = $l->details['library_material_id'] ?? null) ? ['id' => (int) $id, 'name' => $materials[$id] ?? null] : null,
                'description' => $l->description,
                'quantity' => $l->quantity !== null ? (string) $l->quantity : ($l->details['quantity'] ?? null),
                'value' => $this->money($l->amount),
                'nature' => $l->nature,
                'status' => $l->status,
                'date' => $l->incurred_at ? \Illuminate\Support\Carbon::parse($l->incurred_at)->toDateString() : null,
                'stores_reference' => $l->details['stores_reference'] ?? null,
                'inventory_log_id' => $l->source_id,
                // Where the cost went: the expense code's account (WIP under
                // CAPITALISE, cost of sales otherwise) — the posting's own answer.
                'debit_account' => $l->expenseCode?->debitAccount?->only(['code', 'name']),
                'posted' => (bool) $l->posted_at,
            ])->values(),
            'meta' => $this->meta($page),
        ]);
    }

    /** GET api/finance/inventory/adjustments — Stores adjustments and write-offs, and whether each reached the ledger. */
    public function adjustments(Request $request): JsonResponse
    {
        $this->authoriseRead($request);
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $page = InventoryLog::query()->with('material:id,material_name,material_code,unit_cost')
            ->whereIn('type', ['adjustment', 'defective'])
            ->when($filters['date_from'] ?? null, fn (Builder $q, $d) => $q->whereDate('logged_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $d) => $q->whereDate('logged_at', '<=', $d))
            ->orderByDesc('logged_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
        $logs = collect($page->items());
        $names = $this->names($logs->pluck('user_id'));
        $postings = $this->postingsFor($logs->pluck('id'));

        return response()->json([
            'data' => $logs->map(function (InventoryLog $log) use ($names, $postings) {
                $posting = $postings->get($log->id);

                return [
                    'id' => $log->id,
                    'kind' => $log->type === 'defective' ? 'write_off' : 'adjustment',
                    // Where the movement came from decides whether the ledger heard of it.
                    'source' => $posting['source'] ?? ($log->type === 'defective' ? 'write_off' : 'stock_setting'),
                    'material' => $log->material ? ['id' => $log->material->id, 'code' => $log->material->material_code, 'name' => $log->material->material_name] : null,
                    'quantity' => (string) $log->quantity,
                    'date' => $log->logged_at ? \Illuminate\Support\Carbon::parse($log->logged_at)->toDateString() : null,
                    'recorded_by' => ($id = $log->user_id) && $names->has($id) ? ['id' => (int) $id, 'name' => $names[$id]] : null,
                    'reference' => $log->reference_no,
                    'notes' => $log->notes,
                    // The operational fact is the quantity; the value is shown at
                    // the current average. An approved stock count posts once
                    // (JE-STK-*, StockMovementPostingService); a write-off or a
                    // counted balance set on the stock settings posts nothing —
                    // no posting rule exists for them (policy pending).
                    'value_at_current_average' => $this->money(abs((float) $log->quantity) * (float) ($log->material?->unit_cost ?? 0)),
                    'posted_to_ledger' => (bool) ($posting['entry'] ?? null),
                    'journal_entry' => $posting['entry'] ?? null,
                    'mapped_account' => $posting['account'] ?? $this->account($this->accountId(FinanceAccountFunctions::INVENTORY_ADJUSTMENTS)),
                ];
            })->values(),
            'meta' => $this->meta($page),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Stock movements that changed the shelf without reaching the ledger:
     * write-offs and counted balances set outside an approved stock count.
     *
     * @return array{count: int, value_at_current_average: string, posted: false}
     */
    private function unpostedAdjustments(): array
    {
        $logs = InventoryLog::query()->with('material:id,unit_cost')->whereIn('type', ['adjustment', 'defective'])->get(['id', 'material_id', 'quantity']);
        $posted = $this->postingsFor($logs->pluck('id'))->filter(fn (array $p) => $p['entry'] !== null)->keys()->all();
        $logs = $logs->reject(fn (InventoryLog $l) => in_array($l->id, $posted, true));

        return [
            'count' => $logs->count(),
            'value_at_current_average' => $this->money($logs->sum(fn (InventoryLog $l) => abs((float) $l->quantity) * (float) ($l->material?->unit_cost ?? 0))),
            'posted' => false,
        ];
    }

    /**
     * For each adjustment log that came from an approved stock count: the
     * count's journal (JE-STK-{count}) and the account it used — Inventory
     * Adjustments for a cycle count, Opening Balance Equity for opening stock.
     *
     * @return Collection<int, array{source: string, entry: ?array, account: ?array}>
     */
    private function postingsFor(Collection $logIds): Collection
    {
        if ($logIds->isEmpty()) {
            return collect();
        }

        $items = StockCountItem::query()->with('stockCount:id,count_number,mode,status')
            ->whereIn('adjustment_log_id', $logIds)->get(['id', 'stock_count_id', 'adjustment_log_id']);
        $entries = JournalEntry::query()->whereIn('entry_no', $items->map(fn (StockCountItem $i) => $this->stockCountEntryNo($i->stock_count_id))->unique())
            ->get(['id', 'entry_no', 'status'])->keyBy('entry_no');
        $adjustment = $this->account($this->accountId(FinanceAccountFunctions::INVENTORY_ADJUSTMENTS));
        $equity = $this->account($this->accountId(FinanceAccountFunctions::OPENING_BALANCE_EQUITY));

        return $items->mapWithKeys(function (StockCountItem $item) use ($entries, $adjustment, $equity) {
            $opening = $item->stockCount?->mode === StockCount::MODE_OPENING;
            $entry = $entries->get($this->stockCountEntryNo($item->stock_count_id));

            return [(int) $item->adjustment_log_id => [
                'source' => $opening ? 'opening_inventory' : 'stock_count',
                // A count whose differences were all on uncosted materials posts nothing.
                'entry' => $entry ? ['entry_no' => $entry->entry_no, 'status' => $entry->status, 'count' => $item->stockCount?->count_number] : null,
                'account' => $opening ? $equity : $adjustment,
            ]];
        });
    }

    private function stockCountEntryNo(int $countId): string
    {
        return 'JE-STK-'.str_pad((string) $countId, 7, '0', STR_PAD_LEFT);
    }

    private function accountId(string $function): ?int
    {
        $id = ChartOfAccount::query()->where('code', ChartAccountMap::local($function))->value('id');

        return $id ? (int) $id : null;
    }

    private function account(?int $id): ?array
    {
        $account = $id ? ChartOfAccount::query()->whereKey($id)->first(['id', 'code', 'name']) : null;

        return $account ? ['id' => $account->id, 'code' => $account->code, 'name' => $account->name] : null;
    }

    /** Debit-normal balance of an asset account over posted entries. */
    private function debitBalance(int $accountId): string
    {
        $row = JournalLine::query()->where('account_id', $accountId)
            ->whereHas('journalEntry', fn (Builder $q) => $q->where('status', 'posted'))
            ->selectRaw("coalesce(sum(case when entry_type = 'debit' then amount else 0 end),0) - coalesce(sum(case when entry_type = 'credit' then amount else 0 end),0) as balance")
            ->first();

        return $this->money($row->balance ?? 0);
    }

    private function names(Collection $ids): Collection
    {
        $ids = $ids->filter()->map(fn ($id) => (int) $id)->unique()->values();

        return $ids->isEmpty() ? collect() : User::query()->whereIn('id', $ids)->pluck('name', 'id');
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function meta($page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()];
    }

    private function authoriseRead(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403, 'You do not have access to Finance inventory figures.');
    }
}
