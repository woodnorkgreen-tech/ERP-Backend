<?php

namespace Tests\Feature\Finance\Concerns;

use App\Constants\Permissions;
use App\Events\GoodsReceiptRecorded;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\CostCollector\Services\CostVerificationService;
use App\Modules\Finance\CostCollector\Services\ProcurementCostProducer;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Governance\FinanceConfigVersion;
use App\Modules\Finance\Governance\GovernanceRuntime;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\FinanceEventPosting;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Models\VatTreatment;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use App\Modules\Finance\Services\FinanceEventPoster;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\Finance\Support\GovernedPostingAccount;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\GoodsReceiptNote;
use App\Modules\ProcurementStores\Models\GoodsReceiptNoteItem;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Modules\ProcurementStores\Models\RequisitionItem;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Models\StoresFinancePosting;
use App\Modules\ProcurementStores\Models\Supplier;
use App\Modules\ProcurementStores\Services\InventoryService;
use App\Modules\ProcurementStores\Services\PurchaseOrderWorkflow;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\ApprovesGovernedPostingAccounts;

/**
 * The order → receipt → bill → payment → issue fixtures shared by the Report
 * 76A Part 2 and 76A-3 suites. One actor plays every part so the accounting
 * can be pinned; segregation rules have their own tests.
 */
trait ProcurementAccountingFixtures
{
    use ApprovesGovernedPostingAccounts;

    protected User $user;
    protected Supplier $supplier;
    protected int $enquiryId;
    protected int $projectId;
    protected int $inventory;
    protected int $accrued;
    protected int $payable;
    protected int $inputVat;
    protected int $adjustments;
    protected int $variance;
    protected int $internalUse;
    protected int $damage;
    protected int $waste;
    protected ExpenseCode $serviceCode;
    protected ExpenseCode $overheadCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareProcurementFixtures();
    }

    /** Everything setUp() builds, callable on its own by a test that manages its own database state. */
    protected function prepareProcurementFixtures(): void
    {
        $this->seed(FinanceReferenceSeeder::class);

        $permissions = [
            Permissions::FINANCE_PAYABLES_VERIFY, Permissions::APPROVALS_SELF_APPROVE, Permissions::STORES_MANAGE, Permissions::STORES_VIEW,
            Permissions::STORES_REVIEW, Permissions::STORES_MOVEMENT_REVERSE, Permissions::FINANCE_PETTY_CASH_CREATE,
            Permissions::FINANCE_COSTS_VERIFY, Permissions::FINANCE_COSTS_CREATE, Permissions::FINANCE_COSTS_READ,
            Permissions::FINANCE_PAYMENTS_REVERSE, Permissions::FINANCE_REPORTS_VIEW, Permissions::FINANCE_CONFIG_VIEW,
        ];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->user = User::factory()->create(['is_active' => true, 'name' => 'One Actor']);
        // One actor plays every part so the accounting can be pinned; the
        // segregation rules themselves have their own tests.
        $this->user->givePermissionTo($permissions);
        $this->actingAs($this->user, 'sanctum');

        $recoverable = VatTreatment::where('is_recoverable', true)->where('is_active', true)->orderByDesc('rate_percent')->firstOrFail();
        $this->supplier = Supplier::create([
            'supplier_name' => 'Timber & Board Ltd', 'contact_person' => 'Contact', 'phone' => '0700000002',
            'email' => uniqid('s_').'@test.local', 'address' => 'Industrial Area', 'payment_terms' => '30 days',
            'status' => 'Active', 'user_id' => $this->user->id, 'default_vat_treatment_id' => $recoverable->id,
        ]);

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000', 'address' => 'Nairobi',
            'city' => 'Nairobi', 'county' => 'Nairobi', 'customer_type' => 'company', 'lead_source' => 'test',
            'preferred_contact' => 'email', 'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->enquiryId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId, 'title' => 'Stand build', 'contact_person' => 'C',
            'enquiry_number' => 'ENQ-'.uniqid(), 'job_number' => 'WNG-76A2-001', 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->projectId = DB::table('projects')->insertGetId([
            'enquiry_id' => $this->enquiryId, 'project_id' => 'WNG-76A2-001', 'status' => 'in_progress',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $account = fn (string $function) => (int) ChartOfAccount::where('code', ChartAccountMap::local($function))->value('id');
        $this->inventory = $account(FinanceAccountFunctions::INVENTORY);
        $this->accrued = $account(FinanceAccountFunctions::ACCRUED_EXPENSES);
        $this->payable = $account(FinanceAccountFunctions::ACCOUNTS_PAYABLE);
        $this->inputVat = $account(FinanceAccountFunctions::INPUT_VAT);
        $this->adjustments = $account(FinanceAccountFunctions::INVENTORY_ADJUSTMENTS);
        // Report 76A-3: stock adjustments and opening stock post only to an
        // approved account. These suites are not about that approval.
        $this->activateStockPostingAccounts();

        // Accounts an accountant would choose. They exist; nothing is approved yet.
        $this->variance = $this->expenseAccount('T-PPV', 'Purchase price variance');
        $this->internalUse = $this->expenseAccount('T-INT', 'Workshop consumables');
        $this->damage = $this->expenseAccount('T-DMG', 'Stock damage');
        $this->waste = $this->expenseAccount('T-WST', 'Stock waste');

        $this->serviceCode = $this->code('T2-SVC', ExpenseCode::JOB_OPTIONAL, FinanceAccountFunctions::WIP_SUBCONTRACTORS);
        $this->overheadCode = ExpenseCode::create([
            'code' => 'T2-OVH', 'accounting_class' => 'Overhead', 'expense_family' => 'Facilities', 'expense_type' => 'Office services',
            'simple_meaning' => 'Office services', 'job_id_rule' => ExpenseCode::JOB_NOT_ALLOWED, 'cash_flow_class' => 'operating',
            'default_debit_account_id' => $this->expenseAccount('T-OVH', 'Office services'), 'is_active' => true, 'is_procurable' => true,
        ]);
    }

    /* ── fixtures ────────────────────────────────────────────────────────── */

    protected function expenseAccount(string $code, string $name): int
    {
        $account = ChartOfAccount::where('category', 'expense')->where('is_postable', true)->firstOrFail()->replicate();
        $account->forceFill(['code' => $code, 'name' => $name, 'is_active' => true, 'is_postable' => true])->save();

        return (int) $account->id;
    }

    protected function code(string $code, string $rule, string $function): ExpenseCode
    {
        return ExpenseCode::create([
            'code' => $code, 'accounting_class' => 'Direct project cost', 'expense_family' => 'Subcontractors',
            'expense_type' => $code, 'simple_meaning' => $code, 'job_id_rule' => $rule, 'cash_flow_class' => 'operating',
            'default_debit_account_id' => ChartOfAccount::where('code', ChartAccountMap::local($function))->value('id'),
            'default_debit_gl' => $function.' test', 'is_active' => true, 'is_procurable' => true,
        ]);
    }

    /** Approve and activate a governed posting account, through Finance Setup's own API. */
    protected function approveAccount(string $function, int $accountId): void
    {
        $role = Role::findOrCreate('Super Admin', 'web');
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole($role);
        $admin->givePermissionTo(Permission::findOrCreate(Permissions::FINANCE_CONFIG_VIEW, 'web'));

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/finance/governance/items/'.GovernedPostingAccount::item($function).'/apply', [
                'value' => ['account_code' => ChartOfAccount::whereKey($accountId)->value('code')],
            ])->assertOk()->assertJsonPath('data.state', 'active');

        GovernanceRuntime::flush();
        $this->actingAs($this->user, 'sanctum');
    }

    protected function material(string $name, float $unitCost = 0): LibraryMaterial
    {
        $workstation = DB::table('workstations')->insertGetId(['name' => 'W', 'code' => 'W-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]);

        return LibraryMaterial::create([
            'workstation_id' => $workstation, 'material_name' => $name, 'material_code' => 'M-'.uniqid(), 'category' => 'Materials',
            'material_type' => 'consumable', 'tracking_mode' => 'bulk_quantity', 'issue_disposition' => 'consumed',
            'unit_of_measure' => 'pcs', 'unit_cost' => $unitCost, 'item_status' => 'Active', 'is_active' => true,
        ]);
    }

    /**
     * An approved order with one line. $materialId null = a non-stock purchase.
     * $forProject false = a department's purchase (overhead).
     */
    protected function order(float $quantity, float $unitPrice, ?int $materialId, ?ExpenseCode $code = null, bool $forProject = true): PurchaseOrderItem
    {
        $departmentId = $forProject ? null : DB::table('departments')->insertGetId(['name' => 'Admin '.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        if ($departmentId) {
            DB::table('cost_centres')->insert(['code' => 'CC-'.uniqid(), 'name' => 'Administration', 'hr_department_id' => $departmentId,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $requisition = Requisition::create([
            'requisition_number' => 'PR-T-'.uniqid(), 'date' => now()->toDateString(), 'requested_by_type' => $forProject ? 'project' : 'office',
            'urgency' => 'normal', 'total_amount' => $quantity * $unitPrice, 'status' => 'approved', 'user_id' => $this->user->id,
            'project_id' => $forProject ? $this->projectId : null, 'department_id' => $departmentId,
        ]);
        $reqItem = RequisitionItem::create([
            'requisition_id' => $requisition->id, 'material_id' => $materialId, 'custom_description' => $materialId ? null : 'Stand installation',
            'expense_code_id' => $code?->id, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'total' => $quantity * $unitPrice, 'purpose' => 'test',
        ]);
        $order = PurchaseOrder::create([
            'po_number' => 'PO-T-'.uniqid(), 'date' => now()->toDateString(), 'supplier_id' => $this->supplier->id,
            'requisition_id' => $requisition->id, 'due_date' => now()->addDays(7)->toDateString(), 'delivery_address' => 'Karen Village Store',
            'description' => 'test', 'total_amount' => $quantity * $unitPrice, 'status' => 'approved', 'user_id' => $this->user->id,
            'approved_at' => now(), 'approved_by' => $this->user->id,
        ]);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'requisition_item_id' => $reqItem->id, 'material_id' => $materialId,
            'custom_description' => $materialId ? null : 'Stand installation', 'quantity' => $quantity, 'unit_price' => $unitPrice,
            'total' => $quantity * $unitPrice,
        ]);
        app(ProcurementCostProducer::class)->postPurchaseOrder($order->id);

        return $item;
    }

    /** A goods receipt (or, for a non-stock line, the receipt/service confirmation). */
    protected function receive(PurchaseOrderItem $item, float $quantity, bool $shelve = true): GoodsReceiptNoteItem
    {
        $note = GoodsReceiptNote::create([
            'grn_number' => 'GRN-T-'.uniqid(), 'date' => now()->toDateString(), 'purchase_order_id' => $item->purchase_order_id,
            'batch_number' => 'B-'.uniqid(), 'store_location' => 'Karen Village Store', 'quality_check' => 'pass',
            'store_status' => 'confirmed', 'received_by' => $this->user->id,
        ]);
        $line = GoodsReceiptNoteItem::create([
            'goods_receipt_note_id' => $note->id, 'purchase_order_item_id' => $item->id, 'material_id' => $item->material_id,
            'ordered_quantity' => $item->quantity, 'received_quantity' => $quantity, 'condition' => 'good', 'accepted' => true,
            'store_status' => 'confirmed', 'stock_status' => $item->material_id ? 'awaiting_stores_details' : 'not_stocked',
            'receipt_unit_cost' => $item->unit_price, 'unit_price' => $item->unit_price,
        ]);
        event(new GoodsReceiptRecorded($note->id));
        // A stocked line is confirmed onto the shelf by Stores; only then can
        // an invoice be matched against it.
        if ($shelve && $item->material_id) {
            $this->shelve($line);
        }

        return $line->fresh();
    }

    /** Stores puts a received line on the shelf, through the real receiving endpoint. */
    protected function shelve(GoodsReceiptNoteItem $line): void
    {
        $this->postJson('/api/procurement-stores/movements', ['type' => 'receive', 'lines' => [[
            'material_id' => $line->material_id, 'quantity' => (float) $line->received_quantity, 'grn_item_id' => $line->id,
        ]]])->assertSuccessful();
    }

    protected function bill(PurchaseOrderItem $item, float $net, float $vat = 0, array $more = []): Bill
    {
        $response = $this->postJson('/api/procurement-stores/bills', $more + [
            'purchase_order_id' => $item->purchase_order_id, 'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'amount' => $net + $vat, 'vat_amount' => $vat, 'wht_amount' => 0, 'supplier_invoice_number' => 'SINV-'.uniqid(),
            'etims_invoice_no' => 'ETIMS-'.uniqid(),
        ]);
        $response->assertSuccessful();

        return Bill::findOrFail($response->json('data.id') ?? $response->json('id'));
    }

    protected function verify(Bill $bill)
    {
        return $this->postJson("/api/procurement-stores/bills/{$bill->id}/verify");
    }

    protected function pay(Bill $bill): Payment
    {
        $bank = PaymentSource::where('type', 'bank')->where('is_active', true)->whereNotNull('gl_account_id')->firstOrFail();
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/record-payment", [
            'amount_paid' => (float) $bill->fresh()->balance, 'payment_date' => now()->toDateString(), 'payment_source_id' => $bank->id,
            'payment_method' => 'bank_transfer', 'reference_number' => 'TRX-'.uniqid(),
        ])->assertSuccessful();

        return Payment::latest('id')->firstOrFail();
    }

    protected function balance(int $accountId): string
    {
        return number_format((float) DB::table('journal_lines')->where('account_id', $accountId)
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'debit' THEN amount ELSE -amount END), 0) AS b")->value('b'), 2, '.', '');
    }

    protected function projectActual(): string
    {
        return number_format((float) CostLine::where('project_enquiry_id', $this->enquiryId)->counting()
            ->where('nature', CostLine::NATURE_ACTUAL)->sum('net_amount'), 2, '.', '');
    }

    protected function projectLines(string $nature): string
    {
        return number_format((float) CostLine::where('project_enquiry_id', $this->enquiryId)->counting()
            ->where('nature', $nature)->sum('net_amount'), 2, '.', '');
    }

    protected function unbalancedJournals(): int
    {
        return (int) DB::table('journal_entries as je')->join('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
            ->groupBy('je.id')->havingRaw("ROUND(SUM(CASE WHEN jl.entry_type='debit' THEN jl.amount ELSE -jl.amount END),2) <> 0")
            ->get(['je.id'])->count();
    }

    protected function stockOnHand(LibraryMaterial $material): float
    {
        return (float) Stock::where('material_id', $material->id)->value('quantity_on_hand');
    }

    protected function move(string $type, array $line)
    {
        return $this->postJson('/api/procurement-stores/movements', ['type' => $type, 'lines' => [$line]]);
    }

    protected function costCentre(): int
    {
        return (int) DB::table('cost_centres')->where('is_active', true)->value('id');
    }

    protected function stocked(string $name, float $quantity, float $unitCost): LibraryMaterial
    {
        $material = $this->material($name);
        $this->move('receive', ['material_id' => $material->id, 'quantity' => $quantity, 'receipt_unit_cost' => $unitCost,
            'receipt_reason' => InventoryLog::REASON_OPENING_BALANCE, 'notes' => 'Opening stock for this test material'])->assertSuccessful();

        return $material;
    }

    protected function recount(LibraryMaterial $material, float $counted, string $reason): void
    {
        $reviewer = User::factory()->create(['is_active' => true]);
        $reviewer->givePermissionTo([Permissions::STORES_REVIEW, Permissions::STORES_MANAGE]);

        $id = $this->postJson('/api/procurement-stores/stock-counts', ['counted_on' => now()->toDateString()])->assertSuccessful()->json('data.id');
        $item = DB::table('stock_count_items')->where('stock_count_id', $id)->where('material_id', $material->id)->first();
        $this->putJson("/api/procurement-stores/stock-counts/{$id}", ['items' => [[
            'id' => $item->id, 'counted_quantity' => $counted, 'variance_reason' => $reason,
        ]]])->assertSuccessful();
        $this->postJson("/api/procurement-stores/stock-counts/{$id}/submit")->assertSuccessful();
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/procurement-stores/stock-counts/{$id}/approve", [])->assertSuccessful();
        $this->actingAs($this->user, 'sanctum');
    }

    /**
     * A bill entered by its lines (Report 76A-3): quantity and the net unit
     * price actually charged, matched by the server to accepted receipts.
     *
     * @param  array<int, array<string, mixed>>  $lines  each: purchase_order_item_id, quantity, unit_price_net, …
     */
    protected function billLines(int $purchaseOrderId, array $lines, float $vat = 0, array $more = [])
    {
        $net = array_reduce($lines, fn ($sum, $line) => $sum + round($line['quantity'] * $line['unit_price_net'], 2) - ($line['discount_amount'] ?? 0), 0.0);

        return $this->postJson('/api/procurement-stores/bills', $more + [
            'purchase_order_id' => $purchaseOrderId, 'bill_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'amount' => round($net + $vat, 2), 'vat_amount' => $vat, 'wht_amount' => 0, 'supplier_invoice_number' => 'SINV-'.uniqid(),
            'etims_invoice_no' => 'ETIMS-'.uniqid(), 'lines' => $lines,
        ]);
    }

    protected function billed($response): Bill
    {
        $response->assertSuccessful();

        return Bill::findOrFail($response->json('data.id') ?? $response->json('id'));
    }
}
