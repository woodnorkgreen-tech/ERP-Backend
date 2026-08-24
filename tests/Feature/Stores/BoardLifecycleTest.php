<?php

namespace Tests\Feature\Stores;

use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\Board;
use App\Modules\ProcurementStores\Models\BoardRequest;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use App\Modules\ProcurementStores\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The board lifecycle moves physical stock and posts project cost, and it does
 * so outside InventoryService::adjustStock — it owns its own stock arithmetic.
 * These tests pin the invariants that ownership rests on.
 */
class BoardLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private LibraryMaterial $material;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The workflow notifies by role — registering an offcut raises a racking
        // task for Stores. Spatie throws if a queried role does not exist, so the
        // roles the lifecycle addresses must be present regardless of who acts.
        foreach (['Stores', 'Production', 'Manager', 'Super Admin', 'Logistics'] as $role) {
            Role::findOrCreate($role);
        }

        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'Board Shop', 'code' => 'WS-BRD-' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // tracking_mode + issue_disposition together are what isBoardTrackable()
        // reads first, so the fixture does not depend on category seeding.
        $this->material = LibraryMaterial::create([
            'workstation_id' => $workstationId,
            'material_name' => 'MDF 18mm Sheet',
            'material_code' => 'MAT-MDF-' . uniqid(),
            'category' => 'Boards',
            'material_type' => 'reusable',
            'tracking_mode' => 'dimension_piece',
            'issue_disposition' => 'recoverable_remainder',
            'unit_of_measure' => 'sheet',
            'unit_cost' => 3000.00,
            'item_status' => 'Active',
        ]);

        Stock::create([
            'material_id' => $this->material->id,
            'quantity_on_hand' => 0,
            'quantity_reserved' => 0,
            'warehouse_code' => 'MAIN',
            'tracking_mode' => Stock::TRACK_BY_AREA,
        ]);
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create();
        Role::findOrCreate($role);
        $user->assignRole($role);
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    private function makeBoard(array $attributes = []): Board
    {
        Stock::where('material_id', $this->material->id)->increment('quantity_on_hand', 1);

        return Board::create(array_merge([
            'tracking_code' => 'WNG-MDF-' . uniqid(),
            'library_material_id' => $this->material->id,
            'batch_number' => 'BATCH-001',
            'length' => 2440, 'width' => 1220, 'thickness' => 18,
            'area_m2' => 2.9768,
            'current_value' => 3000.00,
            'status' => 'Available',
        ], $attributes));
    }

    private function onHand(): float
    {
        return (float) Stock::where('material_id', $this->material->id)->value('quantity_on_hand');
    }

    // ── Issue ────────────────────────────────────────────────────────────────

    public function test_fulfilment_cannot_issue_more_boards_than_the_request_reserved(): void
    {
        $this->actAs('Stores');
        $boards = collect(range(1, 3))->map(fn () => $this->makeBoard());

        $request = BoardRequest::create([
            'job_ref' => 'WNG-01-2026-001', 'material_id' => $this->material->id,
            'qty_requested' => 1, 'qty_fulfilled' => 0, 'status' => 'pending',
            'requested_by' => auth()->id(),
        ]);

        $this->postJson("/api/procurement-stores/board-requests/{$request->id}/fulfil", [
            'board_ids' => $boards->pluck('id')->all(),
        ])->assertStatus(422);

        $this->assertSame(0, (int) $request->fresh()->qty_fulfilled);
        $this->assertSame(0, Board::where('status', 'Allocated')->count());
    }

    public function test_boards_cannot_be_received_for_a_material_with_no_price(): void
    {
        $this->actAs('Stores');
        $this->material->update(['unit_cost' => 0]);

        $this->postJson('/api/procurement-stores/check-in', [
            'material_id' => $this->material->id,
            'quantity' => 2,
        ])->assertStatus(422);

        $this->assertSame(0, Board::where('library_material_id', $this->material->id)->count());
        $this->assertSame(0.0, $this->onHand());
    }

    public function test_a_receipt_price_lets_an_unpriced_material_be_received(): void
    {
        $this->actAs('Stores');
        $this->material->update(['unit_cost' => 0]);

        $this->postJson('/api/procurement-stores/check-in', [
            'material_id' => $this->material->id,
            'quantity' => 2,
            'receipt_unit_cost' => 2750.00,
        ])->assertStatus(200);

        $boards = Board::where('library_material_id', $this->material->id)->get();
        $this->assertCount(2, $boards);
        $this->assertTrue($boards->every(fn (Board $board) => (float) $board->current_value === 2750.00));
    }

    public function test_a_board_without_a_recorded_value_cannot_be_issued(): void
    {
        $this->actAs('Stores');
        $board = $this->makeBoard(['current_value' => 0]);

        $request = BoardRequest::create([
            'job_ref' => 'WNG-01-2026-002', 'material_id' => $this->material->id,
            'qty_requested' => 1, 'qty_fulfilled' => 0, 'status' => 'pending',
            'requested_by' => auth()->id(),
        ]);

        $this->postJson("/api/procurement-stores/board-requests/{$request->id}/fulfil", [
            'board_ids' => [$board->id],
        ])->assertStatus(422);

        $this->assertSame('Available', $board->fresh()->status);
    }

    public function test_cancelling_a_request_twice_does_not_release_the_reservation_twice(): void
    {
        $this->actAs('Stores');
        Stock::where('material_id', $this->material->id)->update(['quantity_reserved' => 2]);

        $request = BoardRequest::create([
            'job_ref' => 'WNG-01-2026-003', 'material_id' => $this->material->id,
            'qty_requested' => 2, 'qty_fulfilled' => 0, 'status' => 'pending',
            'requested_by' => auth()->id(),
        ]);

        $this->deleteJson("/api/procurement-stores/board-requests/{$request->id}")->assertOk();
        $this->deleteJson("/api/procurement-stores/board-requests/{$request->id}")->assertStatus(422);

        $reserved = (float) Stock::where('material_id', $this->material->id)->value('quantity_reserved');
        $this->assertSame(0.0, $reserved, 'Reserved stock must never be released more than once.');
    }

    // ── Consumption ──────────────────────────────────────────────────────────

    public function test_an_allocated_board_can_be_consumed_without_an_explicit_dispatch_step(): void
    {
        $this->actAs('Production');
        $board = $this->makeBoard(['status' => 'Allocated', 'assigned_job_ref' => 'WNG-01-2026-004']);

        $this->postJson("/api/procurement-stores/boards/{$board->id}/consume", [
            'notes' => 'Fully used on site',
        ])->assertOk();

        $this->assertSame('Consumed', $board->fresh()->status);
    }

    public function test_a_racked_board_cannot_be_recorded_as_used(): void
    {
        $this->actAs('Production');
        $board = $this->makeBoard(['status' => 'Available']);

        $this->postJson("/api/procurement-stores/boards/{$board->id}/consume", [])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'not out on a job'));

        $this->assertSame('Available', $board->fresh()->status);
    }

    // ── Offcuts ──────────────────────────────────────────────────────────────

    public function test_declaring_an_offcut_adds_no_stock_and_keeps_the_parent_identity(): void
    {
        $this->actAs('Production');
        $issue = InventoryLog::create([
            'material_id' => $this->material->id, 'user_id' => auth()->id(),
            'type' => 'check_out', 'usage_type' => 'reusable', 'quantity' => -1,
            'balance_after' => 0, 'reference_no' => 'WNG-01-2026-005', 'logged_at' => now(),
        ]);
        $board = $this->makeBoard([
            'status' => 'WIP', 'assigned_job_ref' => 'WNG-01-2026-005',
            'original_issue_log_id' => $issue->id, 'project_material_id' => null,
        ]);

        $before = $this->onHand();

        $this->postJson("/api/procurement-stores/boards/{$board->id}/consume", [
            'offcut_length' => 1000, 'offcut_width' => 600,
        ])->assertOk();

        $this->assertSame($before, $this->onHand(), 'Declaring an offcut must not add Stores stock.');

        $offcut = Board::where('parent_board_id', $board->id)->firstOrFail();
        $this->assertSame('Quarantine', $offcut->status, 'An undeclared offcut is not yet Stores stock.');
        $this->assertSame($issue->id, (int) $offcut->original_issue_log_id);
        $this->assertSame('WNG-01-2026-005', $offcut->assigned_job_ref);
        $this->assertSame('Consumed', $board->fresh()->status);
    }

    public function test_an_offcut_smaller_than_the_minimum_reusable_size_is_refused(): void
    {
        $this->actAs('Production');
        $this->material->update(['minimum_reusable_length_mm' => 900]);
        $board = $this->makeBoard(['status' => 'WIP', 'assigned_job_ref' => 'WNG-01-2026-006']);

        $this->postJson("/api/procurement-stores/boards/{$board->id}/consume", [
            'offcut_length' => 200, 'offcut_width' => 200,
        ])->assertStatus(422);

        $this->assertSame(0, Board::where('parent_board_id', $board->id)->count());
    }

    public function test_an_offcut_larger_than_its_parent_is_refused(): void
    {
        $this->actAs('Production');
        $board = $this->makeBoard(['status' => 'WIP', 'assigned_job_ref' => 'WNG-01-2026-007']);

        $this->postJson("/api/procurement-stores/boards/{$board->id}/consume", [
            'offcut_length' => 9999, 'offcut_width' => 9999,
        ])->assertStatus(422);

        $this->assertSame(0, Board::where('parent_board_id', $board->id)->count());
    }

    // ── Requirement reopening ────────────────────────────────────────────────

    public function test_a_recovered_offcut_does_not_reopen_the_approved_requirement(): void
    {
        $issue = InventoryLog::create([
            'material_id' => $this->material->id, 'user_id' => User::factory()->create()->id,
            'type' => 'check_out', 'usage_type' => 'reusable', 'quantity' => -1,
            'balance_after' => 0, 'project_material_id' => null, 'logged_at' => now(),
        ]);
        InventoryLog::create([
            'material_id' => $this->material->id, 'user_id' => $issue->user_id,
            'type' => 'return', 'usage_type' => 'reusable', 'quantity' => 1,
            'balance_after' => 1, 'project_material_id' => null,
            'original_issue_log_id' => $issue->id, 'return_kind' => 'recovered_offcut',
            'logged_at' => now(),
        ]);

        $reopening = (float) InventoryLog::where('original_issue_log_id', $issue->id)
            ->fulfilmentReopeningReturns()->sum('quantity');

        $this->assertSame(0.0, $reopening, 'Recovering an offcut does not mean the project needs another board.');
    }

    public function test_a_whole_board_return_does_reopen_the_approved_requirement(): void
    {
        $issue = InventoryLog::create([
            'material_id' => $this->material->id, 'user_id' => User::factory()->create()->id,
            'type' => 'check_out', 'usage_type' => 'reusable', 'quantity' => -1,
            'balance_after' => 0, 'project_material_id' => null, 'logged_at' => now(),
        ]);
        InventoryLog::create([
            'material_id' => $this->material->id, 'user_id' => $issue->user_id,
            'type' => 'return', 'usage_type' => 'reusable', 'quantity' => 1,
            'balance_after' => 1, 'project_material_id' => null,
            'original_issue_log_id' => $issue->id, 'return_kind' => 'whole_item',
            'logged_at' => now(),
        ]);

        $reopening = (float) InventoryLog::where('original_issue_log_id', $issue->id)
            ->fulfilmentReopeningReturns()->sum('quantity');

        $this->assertSame(1.0, $reopening, 'An unused board coming back reopens the requirement.');
    }

    public function test_a_pre_migration_offcut_return_is_still_read_as_an_offcut(): void
    {
        $issue = InventoryLog::create([
            'material_id' => $this->material->id, 'user_id' => User::factory()->create()->id,
            'type' => 'check_out', 'usage_type' => 'reusable', 'quantity' => -1,
            'balance_after' => 0, 'project_material_id' => null, 'logged_at' => now(),
        ]);
        // Legacy row: no return_kind, identified only by its note.
        $legacy = InventoryLog::create([
            'material_id' => $this->material->id, 'user_id' => $issue->user_id,
            'type' => 'return', 'usage_type' => 'reusable', 'quantity' => 1,
            'balance_after' => 1, 'project_material_id' => null,
            'original_issue_log_id' => $issue->id,
            'notes' => 'Offcut WNG-MDF-2026-0009 physically received',
            'logged_at' => now(),
        ]);
        $legacy->forceFill(['return_kind' => null])->save();

        $reopening = (float) InventoryLog::where('original_issue_log_id', $issue->id)
            ->fulfilmentReopeningReturns()->sum('quantity');

        $this->assertSame(0.0, $reopening, 'The notes fallback must keep pre-migration offcuts correct.');
    }

    // ── Receiving a returned board ───────────────────────────────────────────

    public function test_receiving_a_grade_c_return_adds_stock_but_holds_it_in_quarantine(): void
    {
        $this->actAs('Stores');
        $issue = InventoryLog::create([
            'material_id' => $this->material->id, 'user_id' => auth()->id(),
            'type' => 'check_out', 'usage_type' => 'reusable', 'quantity' => -1,
            'balance_after' => 0, 'reference_no' => 'WNG-01-2026-008', 'logged_at' => now(),
        ]);
        $board = $this->makeBoard([
            'status' => 'Return Initiated', 'assigned_job_ref' => 'WNG-01-2026-008',
            'original_issue_log_id' => $issue->id,
        ]);

        $before = $this->onHand();

        $this->postJson("/api/procurement-stores/boards/{$board->id}/receive-return", [
            'condition_grade' => 'C', 'notes' => 'Water damage along one edge',
        ])->assertOk();

        $fresh = $board->fresh();
        $this->assertSame('Quarantine', $fresh->status);
        $this->assertSame('pending', $fresh->quarantine_review_status);
        $this->assertSame($before + 1, $this->onHand(), 'A damaged board is still physically back in Stores.');
    }

    public function test_a_grade_c_return_requires_damage_notes(): void
    {
        $this->actAs('Stores');
        $board = $this->makeBoard(['status' => 'Return Initiated', 'assigned_job_ref' => 'WNG-01-2026-009']);

        $this->postJson("/api/procurement-stores/boards/{$board->id}/receive-return", [
            'condition_grade' => 'D',
        ])->assertStatus(422);

        $this->assertSame('Return Initiated', $board->fresh()->status);
    }

    public function test_a_board_cannot_be_received_before_its_return_is_initiated(): void
    {
        $this->actAs('Stores');
        $board = $this->makeBoard(['status' => 'Allocated', 'assigned_job_ref' => 'WNG-01-2026-010']);

        $this->postJson("/api/procurement-stores/boards/{$board->id}/receive-return", [
            'condition_grade' => 'A',
        ])->assertStatus(422);

        $this->assertSame('Allocated', $board->fresh()->status);
    }

    // ── Receipt valuation ────────────────────────────────────────────────────

    public function test_recording_a_receipt_valuation_makes_an_unvalued_board_issuable(): void
    {
        $this->actAs('Stores');
        $board = $this->makeBoard(['current_value' => 0]);

        $this->postJson('/api/procurement-stores/boards/record-valuation', [
            'board_ids' => [$board->id],
            'unit_value' => 2800.00,
            'reason' => 'Delivery note DN-4471 from Timber Kenya.',
        ])->assertStatus(200);

        $this->assertSame(2800.00, (float) $board->fresh()->current_value);

        $request = BoardRequest::create([
            'job_ref' => 'WNG-01-2026-020', 'material_id' => $this->material->id,
            'qty_requested' => 1, 'qty_fulfilled' => 0, 'status' => 'pending',
            'requested_by' => auth()->id(),
        ]);

        // The issue itself is what was blocked. Its accounting hand-off needs a
        // configured expense code, which is Finance's fixture, not this one's —
        // fake the announcement and assert the board actually moves.
        Event::fake([\App\Events\Stores\StockIssued::class]);

        $this->postJson("/api/procurement-stores/board-requests/{$request->id}/fulfil", [
            'board_ids' => [$board->id],
        ])->assertStatus(200);

        $this->assertSame('Allocated', $board->fresh()->status);
    }

    public function test_a_receipt_valuation_never_restates_a_board_that_already_has_one(): void
    {
        $this->actAs('Stores');
        $board = $this->makeBoard(['current_value' => 3000.00]);

        // The endpoint resolves its targets through the unvalued query, so a
        // priced board is not in scope at all rather than being silently repriced.
        $this->postJson('/api/procurement-stores/boards/record-valuation', [
            'board_ids' => [$board->id],
            'unit_value' => 9999.00,
            'reason' => 'Attempting to overwrite a settled figure.',
        ])->assertStatus(422);

        $this->assertSame(3000.00, (float) $board->fresh()->current_value);
    }

    public function test_one_valuation_cannot_span_two_receipt_batches(): void
    {
        $this->actAs('Stores');
        $firstReceipt = $this->makeBoard(['current_value' => 0, 'batch_number' => 'RECEIPT-A']);
        $secondReceipt = $this->makeBoard(['current_value' => 0, 'batch_number' => 'RECEIPT-B']);

        $this->postJson('/api/procurement-stores/boards/record-valuation', [
            'board_ids' => [$firstReceipt->id, $secondReceipt->id],
            'unit_value' => 2800.00,
            'reason' => 'A price cannot be evidence for two deliveries.',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Select one receipt batch at a time. A single price cannot be applied across different deliveries.');

        $this->assertSame(0.0, (float) $firstReceipt->fresh()->current_value);
        $this->assertSame(0.0, (float) $secondReceipt->fresh()->current_value);
    }

    public function test_a_board_that_has_left_stores_is_not_repriced_here(): void
    {
        $this->actAs('Stores');
        $board = $this->makeBoard(['current_value' => 0, 'status' => 'WIP', 'assigned_job_ref' => 'WNG-01-2026-021']);

        $this->postJson('/api/procurement-stores/boards/record-valuation', [
            'board_ids' => [$board->id],
            'unit_value' => 2800.00,
            'reason' => 'Late delivery note for a board already on a job.',
        ])->assertStatus(422);

        $this->assertSame(0.0, (float) $board->fresh()->current_value);
    }

    public function test_an_offcut_is_valued_at_its_share_of_the_sheet_price(): void
    {
        $this->actAs('Stores');
        // Half a sheet by area — 1220 × 1220 against a 2440 × 1220 standard.
        $offcut = $this->makeBoard([
            'current_value' => 0, 'is_offcut' => true,
            'length' => 1220, 'width' => 1220, 'area_m2' => 1.4884,
        ]);

        $this->postJson('/api/procurement-stores/boards/record-valuation', [
            'board_ids' => [$offcut->id],
            'unit_value' => 3000.00,
            'reason' => 'Sheet price from invoice INV-9912.',
        ])->assertStatus(200);

        $this->assertSame(1500.00, (float) $offcut->fresh()->current_value);
    }

    public function test_valuing_boards_seeds_a_material_that_had_no_catalogue_cost(): void
    {
        $this->actAs('Stores');
        $this->material->update(['unit_cost' => 0]);
        $board = $this->makeBoard(['current_value' => 0]);

        $this->postJson('/api/procurement-stores/boards/record-valuation', [
            'board_ids' => [$board->id],
            'unit_value' => 2650.00,
            'reason' => 'Supplier invoice INV-1020, first priced receipt.',
        ])->assertStatus(200)->assertJsonPath('catalogue_updated', true);

        $this->assertSame(2650.00, (float) $this->material->fresh()->unit_cost);
    }

    public function test_a_settled_catalogue_price_is_not_overwritten_by_a_repair(): void
    {
        $this->actAs('Stores');
        $board = $this->makeBoard(['current_value' => 0]);

        $this->postJson('/api/procurement-stores/boards/record-valuation', [
            'board_ids' => [$board->id],
            'unit_value' => 2650.00,
            'reason' => 'One delivery note, not a new weighted average.',
        ])->assertStatus(200)->assertJsonPath('catalogue_updated', false);

        $this->assertSame(3000.00, (float) $this->material->fresh()->unit_cost);
    }

    public function test_production_cannot_record_a_board_valuation(): void
    {
        $this->actAs('Production');
        $board = $this->makeBoard(['current_value' => 0]);

        $this->postJson('/api/procurement-stores/boards/record-valuation', [
            'board_ids' => [$board->id],
            'unit_value' => 2800.00,
            'reason' => 'Production should not be setting stock value.',
        ])->assertStatus(403);

        $this->assertSame(0.0, (float) $board->fresh()->current_value);
    }

    public function test_a_default_price_lets_a_material_with_no_receipt_history_be_received(): void
    {
        $this->actAs('Stores');
        $this->material->update(['unit_cost' => 0, 'default_unit_cost' => 2400.00]);

        $this->postJson('/api/procurement-stores/check-in', [
            'material_id' => $this->material->id,
            'quantity' => 2,
        ])->assertStatus(200);

        $boards = Board::where('library_material_id', $this->material->id)->get();
        $this->assertCount(2, $boards);
        $this->assertTrue($boards->every(fn (Board $board) => (float) $board->current_value === 2400.00));
    }

    public function test_a_receipt_price_outranks_the_default_price(): void
    {
        $this->actAs('Stores');
        $this->material->update(['unit_cost' => 0, 'default_unit_cost' => 2400.00]);

        $this->postJson('/api/procurement-stores/check-in', [
            'material_id' => $this->material->id,
            'quantity' => 1,
            'receipt_unit_cost' => 3125.00,
        ])->assertStatus(200);

        $this->assertSame(3125.00, (float) Board::where('library_material_id', $this->material->id)->value('current_value'));
    }

    public function test_a_default_price_never_becomes_the_weighted_average(): void
    {
        $this->actAs('Stores');
        $this->material->update(['unit_cost' => 0, 'default_unit_cost' => 2400.00]);

        // The default priced the boards, but it is not money anyone spent, so
        // the catalogue average must still be built only from real receipts.
        $this->postJson('/api/procurement-stores/check-in', [
            'material_id' => $this->material->id,
            'quantity' => 2,
        ])->assertStatus(200);

        $this->assertSame(0.0, (float) $this->material->fresh()->unit_cost);

        $this->postJson('/api/procurement-stores/check-in', [
            'material_id' => $this->material->id,
            'quantity' => 1,
            'receipt_unit_cost' => 3000.00,
        ])->assertStatus(200);

        $this->assertSame(3000.00, (float) $this->material->fresh()->unit_cost);
        $this->assertSame(2400.00, (float) $this->material->fresh()->default_unit_cost);
    }

    public function test_an_issue_freezes_the_default_price_before_finance_runs(): void
    {
        $this->actAs('Stores');
        $this->material->update(['unit_cost' => 0, 'default_unit_cost' => 2400.00]);
        Stock::where('material_id', $this->material->id)->update(['quantity_on_hand' => 1]);

        $log = app(InventoryService::class)->adjustStock(
            $this->material->id,
            -1,
            'check_out',
            ['recipient_name' => 'Project custodian'],
        );

        $this->assertSame(2400.00, (float) $log->receipt_unit_cost);

        // A later catalogue edit must not rewrite the historical movement that
        // an asynchronous Finance worker will consume.
        $this->material->update(['default_unit_cost' => 3100.00]);
        $this->assertSame(2400.00, (float) $log->fresh()->receipt_unit_cost);
    }

    public function test_a_material_with_neither_price_still_refuses_a_board_receipt(): void
    {
        $this->actAs('Stores');
        $this->material->update(['unit_cost' => 0, 'default_unit_cost' => null]);

        $this->postJson('/api/procurement-stores/check-in', [
            'material_id' => $this->material->id,
            'quantity' => 2,
        ])->assertStatus(422);

        $this->assertSame(0, Board::where('library_material_id', $this->material->id)->count());
    }

    public function test_the_unvalued_list_offers_exactly_the_boards_that_cannot_be_issued(): void
    {
        $this->actAs('Stores');
        $unvalued = $this->makeBoard(['current_value' => 0]);
        $this->makeBoard(['current_value' => 3000.00]);
        $this->makeBoard(['current_value' => 0, 'status' => 'WIP']);

        $response = $this->getJson('/api/procurement-stores/boards/unvalued')->assertStatus(200);

        $this->assertSame(1, $response->json('count'));
        $this->assertSame($unvalued->tracking_code, $response->json('data.0.boards.0.tracking_code'));
    }
}
