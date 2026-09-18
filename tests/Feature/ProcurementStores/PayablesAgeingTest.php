<?php

namespace Tests\Feature\ProcurementStores;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Which supplier bills are still owed, and for how long.
 *
 * No ProcurementStores feature tests and no Bill test coverage existed
 * before this file. These pin the bucket boundaries and that the report
 * reuses `Bill`'s own maintained `balance`/`status` rather than recomputing
 * them — the same filter BillController::getPendingBills() already applies,
 * so the two screens cannot disagree about which bills are open.
 */
class PayablesAgeingTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 8)->startOfDay());

        Permission::findOrCreate(Permissions::FINANCE_REPORTS_VIEW, 'web');

        $this->reader = User::factory()->create(['is_active' => true]);
        $this->reader->givePermissionTo(Permissions::FINANCE_REPORTS_VIEW);

        $this->outsider = User::factory()->create(['is_active' => true]);
    }

    private function supplier(string $name): Supplier
    {
        return Supplier::create([
            'supplier_name' => $name,
            'contact_person' => 'Contact ' . $name,
            'phone' => '0700000000',
            'email' => strtolower(str_replace(' ', '', $name)) . '@test.local',
            'status' => 'Active',
        ]);
    }

    private function bill(Supplier $supplier, string $amount, string $balance, string $status, string $dueDate): Bill
    {
        // Bill::boot()'s `creating` hook always sets balance = amount and
        // paid_amount = 0 on create (the honest default for a bill nobody has
        // paid against yet), so a partial/paid fixture has to overwrite those
        // afterward — exactly as a real payment would, via
        // Bill::updatePaymentStatus(), just without needing a real BillPayment
        // row for what this test is checking.
        $bill = Bill::create([
            'bill_number' => 'BILL-' . uniqid(),
            'purchase_order_id' => null, // a direct bill — not the scenario under test, just the cheapest valid fixture
            'supplier_id' => $supplier->id,
            'bill_date' => '2026-09-01',
            'due_date' => $dueDate,
            'amount' => $amount,
        ]);

        $bill->forceFill([
            'paid_amount' => bcsub($amount, $balance, 2),
            'balance' => $balance,
            'status' => $status,
        ])->save();

        return $bill->fresh();
    }

    private function ageing(array $query = []): array
    {
        return $this->actingAs($this->reader, 'sanctum')
            ->getJson('/api/procurement-stores/bills/ageing?' . http_build_query($query))
            ->assertOk()
            ->json('data');
    }

    public function test_ageing_is_not_readable_without_the_reports_permission(): void
    {
        $this->actingAs($this->outsider, 'sanctum')
            ->getJson('/api/procurement-stores/bills/ageing')
            ->assertForbidden();
    }

    public function test_an_empty_book_returns_zero_buckets_not_an_error(): void
    {
        $data = $this->ageing();

        $this->assertSame(0, $data['totals']['count']);
        $this->assertSame('0.00', $data['totals']['value']);
        $this->assertCount(5, $data['buckets']);
    }

    public function test_a_bill_is_bucketed_by_days_overdue_using_its_maintained_balance(): void
    {
        $supplier = $this->supplier('Timber Merchants');

        $current = $this->bill($supplier, '10000.00', '10000.00', 'pending', '2026-09-08');
        $overdue45 = $this->bill($supplier, '20000.00', '20000.00', 'overdue', '2026-07-25');
        $overdue130 = $this->bill($supplier, '30000.00', '30000.00', 'overdue', '2026-05-01');

        $data = $this->ageing();

        $byId = collect($data['buckets'])->keyBy('id');
        $this->assertSame(1, $byId['current']['count']);
        $this->assertSame(1, $byId['31_60']['count']);
        $this->assertSame(1, $byId['90_plus']['count']);
        $this->assertSame(3, $data['totals']['count']);
        $this->assertSame('60000.00', $data['totals']['value']);

        $rowsById = collect($data['rows'])->keyBy('bill_id');
        $this->assertSame('current', $rowsById[$current->id]['bucket']);
        $this->assertSame('31_60', $rowsById[$overdue45->id]['bucket']);
        $this->assertSame('90_plus', $rowsById[$overdue130->id]['bucket']);
        $this->assertSame('Timber Merchants', $rowsById[$current->id]['supplier_name']);

        $filtered = $this->ageing(['bucket' => '90_plus']);
        $this->assertCount(1, $filtered['rows']);
        $this->assertSame($overdue130->id, $filtered['rows'][0]['bill_id']);
    }

    public function test_a_fully_paid_bill_does_not_appear_in_any_bucket(): void
    {
        $supplier = $this->supplier('Paint Supplies');
        $this->bill($supplier, '15000.00', '0.00', 'paid', '2026-08-01');

        $data = $this->ageing();

        $this->assertSame(0, $data['totals']['count']);
    }

    public function test_a_partially_paid_bill_ages_on_its_remaining_balance(): void
    {
        $supplier = $this->supplier('Hardware Store');
        $bill = $this->bill($supplier, '50000.00', '20000.00', 'partial', '2026-08-15');

        $data = $this->ageing();

        $this->assertSame(1, $data['totals']['count']);
        $this->assertSame('20000.00', $data['totals']['value']);
        $row = collect($data['rows'])->firstWhere('bill_id', $bill->id);
        $this->assertSame('20000.00', $row['balance']);
        $this->assertSame('50000.00', $row['amount']);
        $this->assertSame('30000.00', $row['paid_amount']);
    }

    public function test_a_cancelled_bill_is_excluded_even_with_a_nonzero_balance(): void
    {
        $supplier = $this->supplier('Cancelled Co');
        $this->bill($supplier, '10000.00', '10000.00', 'cancelled', '2026-08-01');

        $data = $this->ageing();

        $this->assertSame(0, $data['totals']['count']);
    }
}
