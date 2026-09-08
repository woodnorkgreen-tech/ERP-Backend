<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Finance\Models\FinanceSetting;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\PurchaseOrderItem;
use App\Modules\ProcurementStores\Models\Requisition;
use App\Modules\ProcurementStores\Models\Supplier;
use App\Modules\ProcurementStores\Services\ProcurementPerformance;
use App\Modules\ProcurementStores\Services\PurchaseApprovalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The speed half of the chain: measuring it, and stopping it asking the same
 * question twice.
 *
 * Every timestamp needed to measure this was already being written and nothing
 * read any of them as a duration; meanwhile a KES 500 stapler and a KES 2m truss
 * order took the identical route, and the requisition and the order each carried
 * their own approval.
 *
 * The auto-approval tests matter most for what they refuse: an order that
 * outgrew its requisition or never had one always reaches a person, and an
 * unsigned limit caps nothing. What they now also pin is the rule going the
 * other way — cover alone approves, so one purchase asks for one approval.
 */
class ProcurementSpeedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceSettingsSeeder::class);
        // Approving an order posts its committed cost, and a cost needs a period
        // and a chart to land in. Auto-approval reaches that path from submit(),
        // so this fixture needs the same finance groundwork the accrual tests do.
        $this->seed(\App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder::class);

        Role::findOrCreate('Procurement', 'web');
        Role::findOrCreate('Super Admin', 'web');
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('Super Admin');
        Sanctum::actingAs($this->user);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Timber Ltd', 'contact_person' => 'C',
            'phone' => '0700000000', 'email' => uniqid() . '@t.local', 'address' => 'Nairobi',
            'payment_terms' => '30 days', 'status' => 'Active', 'user_id' => $this->user->id,
        ]);
    }

    /** Finance sets a limit AND signs it off. Both, or it does nothing. */
    private function limit(float $amount, bool $approved = true): void
    {
        FinanceSetting::where('key', PurchaseApprovalPolicy::LIMIT_KEY)->delete();

        DB::table('finance_settings')->insert([
            'key' => PurchaseApprovalPolicy::LIMIT_KEY,
            'value' => json_encode($amount),
            'label' => 'Purchase order auto-approval limit (KES)',
            'effective_from' => '2020-01-01',
            'approved_by' => $approved ? $this->user->id : null,
            'approved_at' => $approved ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function requisition(float $total, string $status = 'approved'): Requisition
    {
        return Requisition::create([
            'requisition_number' => Requisition::generateRequisitionNumber(),
            'date' => now()->toDateString(),
            'requested_by_type' => 'office',
            'urgency' => 'normal',
            'status' => $status,
            'total_amount' => $total,
            'submitted_at' => $status === 'draft' ? null : now()->subDay(),
            'approved_at' => $status === 'approved' ? now() : null,
            'approved_by' => $status === 'approved' ? $this->user->id : null,
            'user_id' => $this->user->id,
        ]);
    }

    private function order(float $total, ?Requisition $requisition = null): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'po_number' => 'PO-' . uniqid(), 'date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id, 'requisition_id' => $requisition?->id,
            'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Store', 'description' => 'Goods',
            'total_amount' => $total, 'status' => 'pending', 'user_id' => $this->user->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id, 'custom_description' => 'Item',
            'quantity' => 1, 'unit_price' => $total, 'total' => $total,
        ]);

        return $order;
    }

    private function submit(PurchaseOrder $order)
    {
        return $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/submit");
    }

    // ---- Auto-approval: what it refuses ----

    /**
     * Cover is the rule, and it stands on its own.
     *
     * This used to assert the opposite — that nothing approved itself until
     * Finance set and signed a limit. That made "one approval per purchase"
     * wait on a number nobody had entered, so in practice every purchase was
     * still approved twice. What protects the money is an approved requisition
     * for at least this much, and that is now sufficient by itself.
     */
    public function test_an_order_covered_by_its_requisition_approves_itself(): void
    {
        $order = $this->order(500, $this->requisition(500));

        $response = $this->submit($order)->assertOk();

        $this->assertSame('approved', $order->fresh()->status);
        $this->assertTrue($response->json('approval.auto'));
        $this->assertStringContainsString('covered by requisition', $response->json('approval.reason'));
    }

    /**
     * The seeder's own rule: a threshold nobody approved must be visible as
     * unapproved, not quietly enforced. The limit is now an optional ceiling
     * rather than the switch, so an unsigned one caps nothing — it must not
     * tighten the flow any more than it may loosen it, and cover still decides.
     */
    public function test_an_unapproved_limit_caps_nothing(): void
    {
        $this->limit(10000, approved: false);
        $order = $this->order(500, $this->requisition(500));

        $this->submit($order)->assertOk();

        $this->assertSame('approved', $order->fresh()->status);
        $this->assertNull(app(PurchaseApprovalPolicy::class)->autoApprovalLimit());
    }

    public function test_an_order_above_the_limit_still_goes_to_a_person(): void
    {
        $this->limit(10000);
        $order = $this->order(25000, $this->requisition(25000));

        $this->submit($order)->assertOk();

        $this->assertSame('pending_approval', $order->fresh()->status);
    }

    /** Not a small-purchase exemption: no requisition means nobody agreed to the need. */
    public function test_a_small_order_with_no_requisition_still_goes_to_a_person(): void
    {
        $this->limit(10000);
        $order = $this->order(500);

        $response = $this->submit($order)->assertOk();

        $this->assertSame('pending_approval', $order->fresh()->status);
        $this->assertStringContainsString('without a requisition', $response->json('approval.reason'));
    }

    public function test_an_order_whose_requisition_is_not_approved_goes_to_a_person(): void
    {
        $this->limit(10000);
        $order = $this->order(500, $this->requisition(500, status: 'pending_approval'));

        $this->submit($order)->assertOk();

        $this->assertSame('pending_approval', $order->fresh()->status);
    }

    /** The requisition is the approval, so the order may not quietly outgrow it. */
    public function test_an_order_that_grew_past_its_requisition_goes_to_a_person(): void
    {
        $this->limit(10000);
        $order = $this->order(900, $this->requisition(500));

        $response = $this->submit($order)->assertOk();

        $this->assertSame('pending_approval', $order->fresh()->status);
        $this->assertStringContainsString('needs approving', $response->json('approval.reason'));
    }

    // ---- Auto-approval: the one case it allows ----

    public function test_an_order_already_covered_by_its_requisition_approves_itself(): void
    {
        $this->limit(10000);
        $requisition = $this->requisition(5000);
        $order = $this->order(5000, $requisition);

        $response = $this->submit($order)->assertOk();
        $order->refresh();

        $this->assertSame('approved', $order->status);
        $this->assertNotNull($order->approved_at);
        $this->assertTrue($response->json('approval.auto'));
        $this->assertStringContainsString($requisition->requisition_number, $response->json('approval.reason'));
    }

    public function test_an_order_priced_exactly_at_its_requisition_is_covered(): void
    {
        $this->limit(10000);
        $order = $this->order(10000, $this->requisition(10000));

        $this->submit($order)->assertOk();

        $this->assertSame('approved', $order->fresh()->status);
    }

    // ---- Measurement ----

    public function test_it_measures_how_long_each_stage_took(): void
    {
        $requisition = $this->requisition(5000);
        $requisition->forceFill([
            'created_at' => now()->subDays(10),
            'submitted_at' => now()->subDays(8),
            'approved_at' => now()->subDays(5),
        ])->save();

        $stages = collect(app(ProcurementPerformance::class)->stages()['stages'])->keyBy('key');

        $this->assertSame(2.0, $stages['requisition_raised_to_submitted']['median_days']);
        $this->assertSame(3.0, $stages['requisition_approval']['median_days']);
        $this->assertSame('Approver', $stages['requisition_approval']['owner']);
    }

    public function test_a_stage_nothing_has_completed_reports_nothing_rather_than_zero(): void
    {
        $this->requisition(5000, status: 'draft');

        $stages = collect(app(ProcurementPerformance::class)->stages()['stages'])->keyBy('key');

        $this->assertSame(0, $stages['requisition_approval']['completed']);
        $this->assertNull($stages['requisition_approval']['median_days']);
    }

    public function test_it_derives_supplier_lead_time_and_on_time_delivery(): void
    {
        // Promised in 7 days, arrived in 5 — early.
        $early = $this->order(1000);
        $early->forceFill([
            'date' => now()->subDays(20)->toDateString(),
            'due_date' => now()->subDays(13)->toDateString(),
        ])->save();
        $this->receive($early, now()->subDays(15));

        // Promised in 7 days, arrived in 11 — four days late.
        $late = $this->order(1000);
        $late->forceFill([
            'date' => now()->subDays(20)->toDateString(),
            'due_date' => now()->subDays(13)->toDateString(),
        ])->save();
        $this->receive($late, now()->subDays(9));

        $row = app(ProcurementPerformance::class)->suppliers()
            ->firstWhere('supplier_id', $this->supplier->id);

        $this->assertSame(2, $row['delivered']);
        $this->assertSame(1, $row['on_time']);
        $this->assertSame(1, $row['late']);
        $this->assertSame(50.0, $row['on_time_rate']);
        $this->assertSame(8.0, $row['avg_lead_time_days']);   // (5 + 11) / 2
        $this->assertSame(4.0, $row['avg_days_late']);
    }

    /** An order placed yesterday is not a broken promise. */
    public function test_an_undelivered_order_is_not_counted_as_late(): void
    {
        $this->order(1000);

        $row = app(ProcurementPerformance::class)->suppliers()
            ->firstWhere('supplier_id', $this->supplier->id);

        $this->assertSame(0, $row['delivered']);
        $this->assertSame(0, $row['late']);
        $this->assertNull($row['on_time_rate']);
        $this->assertSame(1, $row['open_orders']);
    }

    public function test_the_endpoint_names_the_slowest_stage(): void
    {
        $requisition = $this->requisition(5000);
        $requisition->forceFill([
            'created_at' => now()->subDays(30),
            'submitted_at' => now()->subDays(29),
            'approved_at' => now()->subDays(8),
        ])->save();

        $response = $this->getJson('/api/procurement-stores/performance')->assertOk();

        $this->assertSame('requisition_approval', $response->json('summary.slowest_stage'));
    }

    public function test_performance_is_closed_to_anyone_without_a_buying_role(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $this->getJson('/api/procurement-stores/performance')->assertStatus(403);
    }

    private function receive(PurchaseOrder $order, $date): void
    {
        DB::table('goods_receipt_notes')->insert([
            'grn_number' => 'GRN-' . uniqid(), 'date' => $date->toDateString(),
            'purchase_order_id' => $order->id, 'batch_number' => 'B-' . uniqid(),
            'store_location' => 'Store', 'quality_check' => 'pass',
            'store_status' => 'confirmed', 'received_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
