<?php

namespace Tests\Feature\PettyCash;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 3: requisition types become data an administrator owns.
 *
 * Before this, every reference to the type model was a read and the only way to
 * add a type was a migration. The behaviour worth asserting is therefore the
 * whole loop — create a type with its own schema, have the form endpoint serve
 * it, and have a type that has been used refuse to vanish.
 */
class RequisitionTypeManagementTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        Permission::findOrCreate(Permissions::FINANCE_REQUISITION_TYPES_MANAGE, 'web');

        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::FINANCE_REQUISITION_TYPES_MANAGE);

        return $user;
    }

    /** RefreshDatabase leaves no reference data, so the test provides its own. */
    private function expenseCodeId(): int
    {
        return DB::table('expense_codes')->insertGetId([
            'code' => 'TEST-001',
            'accounting_class' => 'Operating cost',
            'expense_family' => 'Testing',
            'expense_type' => 'Test expense',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function paymentSourceId(): int
    {
        return DB::table('payment_sources')->insertGetId([
            'code' => 'TEST-FLOAT',
            'name' => 'Test Float',
            'type' => 'petty_cash',
            'currency' => 'KES',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Site Accommodation',
            'description' => 'Overnight stay for site crew.',
            'icon' => 'mdi-bed-outline',
            'recipient_mode' => 'per_item',
            'requires_project' => true,
            'instructions' => ['Attach the booking confirmation.'],
            'sections' => [
                [
                    'key' => 'request',
                    'title' => "What's this for",
                    'builtin' => 'request',
                    'fields' => [
                        ['key' => 'nights', 'label' => 'Nights', 'type' => 'number', 'required' => true, 'width' => 'third'],
                        ['key' => 'board', 'label' => 'Board', 'type' => 'select', 'options' => ['Room only', 'Half board']],
                        ['key' => 'board_notes', 'label' => 'Board notes', 'type' => 'textarea',
                            'visible_when' => ['field' => 'board', 'is' => 'Half board']],
                    ],
                ],
                ['key' => 'charge', 'title' => 'Project and location', 'builtin' => 'project', 'fields' => []],
                ['key' => 'lines', 'title' => 'Who gets paid', 'builtin' => 'items', 'fields' => []],
            ],
            'line_fields' => [
                ['key' => 'hotel', 'label' => 'Hotel', 'type' => 'text', 'required' => true],
            ],
        ], $overrides);
    }

    public function test_a_finance_manager_can_create_a_type_and_the_form_serves_it(): void
    {
        $user = $this->manager();

        $response = $this->actingAs($user)
            ->postJson('/api/finance/petty-cash/requisition-types', $this->payload())
            ->assertCreated();

        $id = $response->json('data.id');
        $type = PettyCashRequisitionType::findOrFail($id);

        // The code is derived rather than demanded of the administrator.
        $this->assertSame('site_accommodation', $type->code);
        $this->assertSame(PettyCashRequisitionType::SCHEMA_VERSION, $type->schema_version);

        $document = $type->schemaDocument();
        $this->assertSame(['request', 'charge', 'lines'], array_column($document['sections'], 'key'));
        $this->assertSame('third', $document['sections'][0]['fields'][0]['width']);
        $this->assertSame(
            ['field' => 'board', 'is' => 'Half board'],
            $document['sections'][0]['fields'][2]['visible_when']
        );
        $this->assertSame('hotel', $document['line_fields'][0]['key']);

        // The v1 columns are kept in step for anything still reading them.
        $this->assertSame(['nights', 'board', 'board_notes'], array_column($type->request_fields, 'key'));
    }

    public function test_a_field_type_the_client_cannot_draw_is_refused(): void
    {
        $payload = $this->payload();
        $payload['sections'][0]['fields'][0]['type'] = 'signature_pad';

        $this->actingAs($this->manager())
            ->postJson('/api/finance/petty-cash/requisition-types', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('sections.0.fields.0.type');
    }

    public function test_two_fields_cannot_share_a_key(): void
    {
        $payload = $this->payload();
        $payload['sections'][0]['fields'][1]['key'] = 'nights';

        $this->actingAs($this->manager())
            ->postJson('/api/finance/petty-cash/requisition-types', $payload)
            ->assertStatus(422);
    }

    public function test_the_core_panels_cannot_be_removed_or_duplicated(): void
    {
        $missingItems = $this->payload();
        array_pop($missingItems['sections']);

        $this->actingAs($this->manager())
            ->postJson('/api/finance/petty-cash/requisition-types', $missingItems)
            ->assertStatus(422)
            ->assertJsonValidationErrors('sections');

        $duplicateRequest = $this->payload();
        $duplicateRequest['sections'][] = [
            'key' => 'another_request', 'title' => 'Duplicate', 'builtin' => 'request', 'fields' => [],
        ];

        $this->actingAs($this->manager())
            ->postJson('/api/finance/petty-cash/requisition-types', $duplicateRequest)
            ->assertStatus(422)
            ->assertJsonValidationErrors('sections');
    }

    public function test_select_fields_need_choices_and_conditions_need_a_real_source(): void
    {
        $payload = $this->payload();
        $payload['sections'][0]['fields'][1]['options'] = [];
        $payload['sections'][0]['fields'][2]['visible_when'] = ['field' => 'missing', 'is' => 'x'];

        $this->actingAs($this->manager())
            ->postJson('/api/finance/petty-cash/requisition-types', $payload)
            ->assertStatus(422);
    }

    public function test_renaming_a_type_keeps_its_stable_code_when_the_editor_omits_it(): void
    {
        $user = $this->manager();
        $id = $this->actingAs($user)
            ->postJson('/api/finance/petty-cash/requisition-types', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $updated = $this->payload(['name' => 'Crew Accommodation']);
        unset($updated['code']);

        $this->actingAs($user)
            ->putJson("/api/finance/petty-cash/requisition-types/{$id}", $updated)
            ->assertOk();

        $type = PettyCashRequisitionType::findOrFail($id);
        $this->assertSame('site_accommodation', $type->code);
        $this->assertSame('Crew Accommodation', $type->name);
    }

    public function test_managing_types_requires_the_permission(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);

        $this->actingAs($outsider)
            ->postJson('/api/finance/petty-cash/requisition-types', $this->payload())
            ->assertForbidden();

        $this->actingAs($outsider)
            ->getJson('/api/finance/petty-cash/requisition-types')
            ->assertForbidden();
    }

    public function test_an_unused_type_is_deleted_outright(): void
    {
        $type = PettyCashRequisitionType::create([
            'code' => 'temp', 'name' => 'Temporary', 'recipient_mode' => 'single',
        ]);

        $this->actingAs($this->manager())
            ->deleteJson("/api/finance/petty-cash/requisition-types/{$type->id}")
            ->assertOk();

        $this->assertNull(PettyCashRequisitionType::find($type->id));
    }

    /**
     * A used type is referenced by every requisition raised under it, and those
     * rows carry its name and a snapshot of its schema. Deleting it would
     * rewrite history, so it retires instead.
     */
    public function test_a_used_type_is_retired_rather_than_deleted(): void
    {
        $type = PettyCashRequisitionType::create([
            'code' => 'used', 'name' => 'Already Used', 'recipient_mode' => 'single',
        ]);

        $departmentId = DB::table('departments')->insertGetId([
            'name' => 'Ops', 'created_at' => now(), 'updated_at' => now(),
        ]);

        PettyCashRequisition::create([
            'requisition_number' => 'TEST-RETIRE-1',
            'user_id' => $this->manager()->id,
            'department_id' => $departmentId,
            'requisition_type_id' => $type->id,
            'category' => $type->name,
            'purpose' => 'Keeps the type alive',
            'total_amount' => 100,
            'status' => 'pending',
        ]);

        $this->actingAs($this->manager())
            ->deleteJson("/api/finance/petty-cash/requisition-types/{$type->id}")
            ->assertOk();

        $type->refresh();
        $this->assertFalse($type->is_active, 'the type should survive, deactivated');
    }

    public function test_the_index_publishes_the_field_types_the_builder_may_offer(): void
    {
        $this->actingAs($this->manager())
            ->getJson('/api/finance/petty-cash/requisition-types')
            ->assertOk()
            ->assertJsonPath('meta.field_types', PettyCashRequisitionType::FIELD_TYPES)
            ->assertJsonPath('meta.schema_version', PettyCashRequisitionType::SCHEMA_VERSION);
    }

    public function test_create_and_update_stamp_the_schema_version_into_the_frozen_type_snapshot(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $departmentId = DB::table('departments')->insertGetId([
            'name' => 'Operations', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $type = PettyCashRequisitionType::create([
            'code' => 'accommodation',
            'name' => 'Accommodation',
            'recipient_mode' => 'single',
            'schema_version' => PettyCashRequisitionType::SCHEMA_VERSION,
            'schema' => [
                'sections' => [[
                    'key' => 'request',
                    'title' => 'Stay details',
                    'builtin' => 'request',
                    'fields' => [['key' => 'nights', 'type' => 'number', 'required' => true]],
                ]],
                'line_fields' => [['key' => 'hotel', 'type' => 'text', 'required' => true]],
            ],
        ]);
        $payload = [
            'department_id' => $departmentId,
            'category' => $type->name,
            'requisition_type_id' => $type->id,
            'purpose' => 'Site visit accommodation',
            'custom_fields' => ['nights' => 2],
            'payee_name' => 'Example Hotel',
            'items' => [[
                'description' => 'Two nights',
                'details' => ['hotel' => 'Example Hotel'],
                'amount' => 12000,
            ]],
        ];

        $created = $this->actingAs($user)
            ->postJson('/api/finance/petty-cash/requisitions', $payload)
            ->assertCreated();

        $requisition = PettyCashRequisition::findOrFail($created->json('data.id'));
        $this->assertSame(
            PettyCashRequisitionType::SCHEMA_VERSION,
            $requisition->type_snapshot['schema_version'],
        );

        // Updating an older row promotes its frozen definition to the current
        // snapshot contract through the same validation/write path.
        $requisition->update(['type_snapshot' => ['name' => 'Legacy snapshot']]);
        $payload['purpose'] = 'Updated site visit accommodation';

        $this->actingAs($user)
            ->putJson("/api/finance/petty-cash/requisitions/{$requisition->id}", $payload)
            ->assertOk();

        $this->assertSame(
            PettyCashRequisitionType::SCHEMA_VERSION,
            $requisition->fresh()->type_snapshot['schema_version'],
        );
    }

    /**
     * The point of the payment defaults: an approved requisition of this kind
     * reaches the payment sheet already classified, so the payer confirms one
     * of a hundred expense codes rather than hunting for it.
     */
    public function test_payment_defaults_round_trip_onto_the_type_and_its_snapshot(): void
    {
        $expenseCodeId = $this->expenseCodeId();
        $paymentSourceId = $this->paymentSourceId();

        $payload = $this->payload([
            'default_expense_code_id' => $expenseCodeId,
            'default_payment_source_id' => $paymentSourceId,
        ]);

        $id = $this->actingAs($this->manager())
            ->postJson('/api/finance/petty-cash/requisition-types', $payload)
            ->assertCreated()
            ->json('data.id');

        $type = PettyCashRequisitionType::findOrFail($id);
        $this->assertSame($expenseCodeId, $type->default_expense_code_id);
        $this->assertSame($paymentSourceId, $type->default_payment_source_id);

        // definition() is what gets frozen onto every requisition, so the
        // classification travels with the record rather than being looked up
        // against a type that may since have changed.
        $definition = $type->definition();
        $this->assertSame($expenseCodeId, $definition['default_expense_code_id']);
        $this->assertSame($paymentSourceId, $definition['default_payment_source_id']);
    }

    public function test_the_builder_is_given_the_lists_it_must_choose_defaults_from(): void
    {
        $this->expenseCodeId();
        $this->paymentSourceId();

        $response = $this->actingAs($this->manager())
            ->getJson('/api/finance/petty-cash/requisition-types')
            ->assertOk();

        $this->assertNotEmpty($response->json('meta.expense_codes'));
        $this->assertNotEmpty($response->json('meta.payment_sources'));
    }

    public function test_an_unknown_expense_code_is_refused(): void
    {
        $this->actingAs($this->manager())
            ->postJson('/api/finance/petty-cash/requisition-types', $this->payload([
                'default_expense_code_id' => 999999,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_expense_code_id');
    }
}
