<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every supplier dropdown used to read the paginated browsing index: twenty
 * rows, newest first. A chooser needs the whole set, by name, and only the
 * suppliers still traded with.
 */
class SupplierOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function supplier(string $name, string $status = 'Active'): Supplier
    {
        return Supplier::create([
            'supplier_name' => $name,
            'contact_person' => 'Contact',
            'phone' => '0700000000',
            'email' => uniqid('supplier_').'@test.local',
            'address' => 'Nairobi',
            'payment_terms' => '30 days',
            'status' => $status,
            'user_id' => auth()->id(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::create([
            'name' => 'Buyer',
            'email' => uniqid('buyer_').'@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]));
    }

    public function test_it_returns_every_active_supplier_not_just_the_first_page(): void
    {
        foreach (range(1, 25) as $n) {
            $this->supplier('Supplier '.str_pad((string) $n, 2, '0', STR_PAD_LEFT));
        }

        $this->getJson('/api/procurement-stores/suppliers/options')
            ->assertOk()
            ->assertJsonCount(25, 'data');
    }

    public function test_it_orders_suppliers_by_name_rather_than_by_when_they_were_added(): void
    {
        $this->supplier('Zebra Timber');
        $this->supplier('Acacia Boards');
        $this->supplier('Mahogany Supplies');

        $names = $this->getJson('/api/procurement-stores/suppliers/options')
            ->assertOk()
            ->json('data.*.supplier_name');

        $this->assertSame(['Acacia Boards', 'Mahogany Supplies', 'Zebra Timber'], $names);
    }

    public function test_a_retired_supplier_is_not_offered(): void
    {
        $this->supplier('Still Trading');
        $this->supplier('Closed Down', 'Inactive');

        $names = $this->getJson('/api/procurement-stores/suppliers/options')
            ->assertOk()
            ->json('data.*.supplier_name');

        $this->assertSame(['Still Trading'], $names);
    }

    /**
     * A record already pointing at a retired supplier must still show who it
     * names, or editing it would silently blank the supplier on save.
     */
    public function test_a_retired_supplier_a_record_already_names_is_still_offered(): void
    {
        $this->supplier('Still Trading');
        $retired = $this->supplier('Closed Down', 'Inactive');

        $response = $this->getJson('/api/procurement-stores/suppliers/options?include='.$retired->id)
            ->assertOk();

        $this->assertSame(['Closed Down', 'Still Trading'], $response->json('data.*.supplier_name'));
        $this->assertFalse($response->json('data.0.is_active'));
    }
}
