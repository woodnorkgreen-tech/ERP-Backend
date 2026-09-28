<?php

namespace Tests\Feature\Procurement;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * W2-2 (confirmed 2026-09-23, Option A): supplier supporting documents, on
 * the same generic finance_attachments mechanism the Wave 1 invoice evidence
 * path uses — no po_attachments/bill_attachments table.
 */
class ProcurementAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate(Permissions::PROCUREMENT_ORDERS_CREATE, 'web');
        Storage::fake('local');
    }

    private function order(int $userId): PurchaseOrder
    {
        $supplier = Supplier::create([
            'supplier_name' => 'Timber & Board Ltd', 'contact_person' => 'Contact',
            'phone' => '0700000003', 'email' => uniqid().'@test.local',
            'address' => 'Industrial Area', 'payment_terms' => '30 days',
            'status' => 'Active', 'user_id' => $userId,
        ]);

        return PurchaseOrder::create([
            'po_number' => 'PO-'.uniqid(), 'date' => now()->toDateString(),
            'supplier_id' => $supplier->id, 'due_date' => now()->addDays(7)->toDateString(),
            'delivery_address' => 'Karen Village Store', 'description' => 'Materials',
            'total_amount' => 100000, 'status' => 'pending', 'user_id' => $userId,
        ]);
    }

    private function bill(PurchaseOrder $order): Bill
    {
        return Bill::create([
            'bill_number' => 'BILL-'.uniqid(),
            'purchase_order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'amount' => 100000,
            'status' => 'pending',
            'supplier_invoice_number' => 'INV-001',
            'user_id' => $order->user_id,
        ]);
    }

    public function test_an_authorized_user_can_attach_a_file_to_a_purchase_order(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->order($user->id);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/attachments", [
            'evidence_type' => 'quotation',
            'file' => UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf'),
        ])->assertCreated();

        $this->assertSame('quotation', $response->json('data.evidence_type'));
        $this->assertSame(1, $order->attachments()->count());
    }

    public function test_a_reference_only_evidence_entry_is_accepted_without_a_file(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->order($user->id);

        Sanctum::actingAs($user);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/attachments", [
            'evidence_type' => 'delivery_note',
            'reference' => 'DN-2026-0091, kept in the site file',
        ])->assertCreated();

        $this->assertSame(1, $order->attachments()->count());
    }

    public function test_an_unauthorized_user_cannot_attach_evidence(): void
    {
        $order = $this->order(User::factory()->create()->id);
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/attachments", [
            'evidence_type' => 'quotation',
            'reference' => 'Q-1',
        ])->assertStatus(403);
    }

    public function test_an_unrecognised_evidence_type_is_refused(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->order($user->id);

        Sanctum::actingAs($user);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/attachments", [
            'evidence_type' => 'not_a_real_category',
            'reference' => 'X',
        ])->assertStatus(422);
    }

    public function test_an_executable_file_is_refused(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->order($user->id);

        Sanctum::actingAs($user);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/attachments", [
            'evidence_type' => 'quotation',
            'file' => UploadedFile::fake()->create('quote.exe', 10, 'application/x-msdownload'),
        ])->assertStatus(422);

        $this->assertSame(0, $order->attachments()->count());
    }

    public function test_attaching_evidence_to_a_bill_works_the_same_way(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::PROCUREMENT_ORDERS_CREATE);
        $bill = $this->bill($this->order($user->id));

        Sanctum::actingAs($user);
        $this->postJson("/api/procurement-stores/bills/{$bill->id}/attachments", [
            'evidence_type' => 'tax_evidence',
            'file' => UploadedFile::fake()->create('etims.pdf', 5, 'application/pdf'),
        ])->assertCreated();

        $this->assertSame(1, $bill->attachments()->count());
    }

    public function test_a_downloaded_attachment_must_belong_to_the_named_purchase_order(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::PROCUREMENT_ORDERS_CREATE);
        $orderA = $this->order($user->id);
        $orderB = $this->order($user->id);

        Sanctum::actingAs($user);
        $created = $this->postJson("/api/procurement-stores/purchase-orders/{$orderA->id}/attachments", [
            'evidence_type' => 'receipt',
            'file' => UploadedFile::fake()->create('receipt.pdf', 5, 'application/pdf'),
        ])->assertCreated();
        $attachmentId = $created->json('data.id');

        // Attempting to fetch order A's attachment through order B's URL must 404.
        $this->getJson("/api/procurement-stores/purchase-orders/{$orderB->id}/attachments/{$attachmentId}/download")
            ->assertStatus(404);

        $this->getJson("/api/procurement-stores/purchase-orders/{$orderA->id}/attachments/{$attachmentId}/download")
            ->assertOk();
    }

    /**
     * Closure gate §4: an attachment belongs to exactly one (source_type,
     * source_id) pair. A PO's own attachment id, replayed against a Bill
     * that happens to exist, must not resolve — even when both routes
     * share the same attachment id numerically, they name different types.
     */
    public function test_a_purchase_order_attachment_cannot_be_fetched_through_the_bill_path(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->order($user->id);
        $bill = $this->bill($order);

        Sanctum::actingAs($user);
        $poAttachmentId = $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/attachments", [
            'evidence_type' => 'receipt',
            'file' => UploadedFile::fake()->create('receipt.pdf', 5, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/procurement-stores/bills/{$bill->id}/attachments/{$poAttachmentId}/download")
            ->assertStatus(404);
    }

    /** Closure gate §4: the reverse direction of the type-mismatch check above. */
    public function test_a_bill_attachment_cannot_be_fetched_through_the_purchase_order_path(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->order($user->id);
        $bill = $this->bill($order);

        Sanctum::actingAs($user);
        $billAttachmentId = $this->postJson("/api/procurement-stores/bills/{$bill->id}/attachments", [
            'evidence_type' => 'supplier_invoice',
            'file' => UploadedFile::fake()->create('invoice.pdf', 5, 'application/pdf'),
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/procurement-stores/purchase-orders/{$order->id}/attachments/{$billAttachmentId}/download")
            ->assertStatus(404);
    }

    /** Closure gate §4: MIME/size validation applies to Procurement evidence exactly as Wave 1 built it. */
    public function test_an_oversized_file_is_refused(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::PROCUREMENT_ORDERS_CREATE);
        $order = $this->order($user->id);

        Sanctum::actingAs($user);
        $this->postJson("/api/procurement-stores/purchase-orders/{$order->id}/attachments", [
            'evidence_type' => 'receipt',
            'file' => UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_listing_evidence_returns_only_that_orders_attachments(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::PROCUREMENT_ORDERS_CREATE);
        $orderA = $this->order($user->id);
        $orderB = $this->order($user->id);

        Sanctum::actingAs($user);
        $this->postJson("/api/procurement-stores/purchase-orders/{$orderA->id}/attachments", [
            'evidence_type' => 'quotation', 'reference' => 'Q-A',
        ])->assertCreated();
        $this->postJson("/api/procurement-stores/purchase-orders/{$orderB->id}/attachments", [
            'evidence_type' => 'quotation', 'reference' => 'Q-B',
        ])->assertCreated();

        $response = $this->getJson("/api/procurement-stores/purchase-orders/{$orderA->id}/attachments")->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Q-A', $response->json('data.0.reference'));
    }
}
