<?php

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Report 76A-3 §8 — the migration is additive and reversible.
 *
 * Schema changes commit implicitly on MySQL, so this test keeps no fixtures:
 * it writes one bare bill row, proves the migration leaves its amounts alone in
 * both directions, and removes the row itself.
 */
class Report76A3MigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_rolls_back_and_forward_without_touching_existing_amounts(): void
    {
        $migration = require database_path('migrations/2026_10_13_000001_report_76a3_bill_lines_credits_and_tax_basis.php');
        $columns = ['amount', 'net_amount', 'vat_amount', 'paid_amount', 'balance', 'status'];
        $user = \App\Models\User::factory()->create(['is_active' => true]);
        $supplier = \App\Modules\ProcurementStores\Models\Supplier::create([
            'supplier_name' => 'Migration Test Supplier', 'contact_person' => 'Contact', 'phone' => '0700000003',
            'email' => uniqid('m_').'@test.local', 'address' => 'Industrial Area', 'payment_terms' => '30 days',
            'status' => 'Active', 'user_id' => $user->id,
        ]);
        $id = DB::table('bills')->insertGetId([
            'supplier_id' => $supplier->id, 'user_id' => $user->id,
            'bill_number' => 'BILL-MIG-76A3', 'bill_date' => now()->toDateString(), 'due_date' => now()->toDateString(),
            'amount' => 11600, 'net_amount' => 10000, 'vat_amount' => 1600, 'paid_amount' => 0, 'balance' => 11600, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = DB::table('bills')->where('id', $id)->first($columns);

        try {
            $migration->down();
            $this->assertFalse(Schema::hasTable('bill_lines'));
            $this->assertFalse(Schema::hasTable('supplier_credit_notes'));
            $this->assertFalse(Schema::hasColumn('bills', 'credited_amount'));
            $this->assertFalse(Schema::hasColumn('purchase_orders', 'price_basis'));
            $this->assertFalse(Schema::hasColumn('purchase_order_items', 'quoted_unit_price'));
            $this->assertEquals($before, DB::table('bills')->where('id', $id)->first($columns));

            $migration->up();
            $this->assertTrue(Schema::hasTable('bill_line_receipt_allocations'));
            $this->assertTrue(Schema::hasColumn('bills', 'reversed_at'));
            $this->assertEquals($before, DB::table('bills')->where('id', $id)->first($columns));
            $this->assertSame(0, DB::table('bill_lines')->count(), 'nothing is backfilled');
            $this->assertSame('0.00', (string) DB::table('bills')->where('id', $id)->value('credited_amount'));
        } finally {
            if (! Schema::hasTable('bill_lines')) {
                $migration->up();
            }
            DB::table('bills')->where('id', $id)->delete();
            $supplier->delete();
            $user->forceDelete();
        }
    }
}
