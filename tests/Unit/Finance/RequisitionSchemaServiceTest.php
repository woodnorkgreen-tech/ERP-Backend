<?php

namespace Tests\Unit\Finance;

use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType;
use App\Modules\Finance\PettyCash\Services\RequisitionSchemaService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RequisitionSchemaServiceTest extends TestCase
{
    public function test_it_validates_and_strips_values_using_the_type_contract(): void
    {
        $type = new PettyCashRequisitionType([
            'recipient_mode' => 'per_item',
            'request_fields' => [['key' => 'travel_date', 'type' => 'date', 'required' => true]],
            'item_fields' => [['key' => 'origin', 'type' => 'text', 'required' => true]],
        ]);

        $result = app(RequisitionSchemaService::class)->validate($type, [
            'custom_fields' => ['travel_date' => '2026-08-23', 'unconfigured' => 'discard me'],
            'items' => [['payee_name' => 'Alex', 'details' => ['origin' => 'Office', 'unknown' => 'discard me']]],
        ]);

        $this->assertSame(['travel_date' => '2026-08-23'], $result['custom_fields']);
        $this->assertSame([['origin' => 'Office']], $result['item_details']);
    }

    public function test_it_rejects_missing_type_specific_and_recipient_data(): void
    {
        $type = new PettyCashRequisitionType([
            'recipient_mode' => 'per_item',
            'request_fields' => [['key' => 'travel_date', 'type' => 'date', 'required' => true]],
        ]);

        $this->expectException(ValidationException::class);
        app(RequisitionSchemaService::class)->validate($type, ['items' => [['details' => []]]]);
    }

    /** A v1 type has no stored document, so one is synthesised from its columns. */
    public function test_it_synthesises_a_v2_document_for_a_type_that_has_never_been_edited(): void
    {
        $type = new PettyCashRequisitionType([
            'recipient_mode' => 'per_item',
            'requires_project' => true,
            'request_fields' => [['key' => 'travel_date', 'type' => 'date']],
            'item_fields' => [['key' => 'origin', 'type' => 'text']],
        ]);

        $document = $type->schemaDocument();

        $this->assertSame(2, $document['schema_version']);
        $this->assertSame(['request', 'charge', 'lines'], array_column($document['sections'], 'key'));
        $this->assertSame(['request', 'project', 'items'], array_column($document['sections'], 'builtin'));
        $this->assertSame('travel_date', $document['sections'][0]['fields'][0]['key']);
        $this->assertSame('Who gets paid', $document['sections'][2]['title']);
        $this->assertSame('origin', $document['line_fields'][0]['key']);
    }

    /** Fields carry their own layout and visibility, with safe defaults. */
    public function test_it_normalises_width_and_visibility_onto_every_field(): void
    {
        $fields = PettyCashRequisitionType::normaliseFields([
            ['key' => 'fuel_type', 'type' => 'select', 'options' => ['Petrol', 'Diesel']],
            ['key' => 'odometer', 'type' => 'number', 'width' => 'full',
                'visible_when' => ['field' => 'fuel_type', 'is' => 'Diesel']],
            ['key' => 'sprawling', 'width' => 'not-a-width', 'visible_when' => 'nonsense'],
        ]);

        $this->assertSame('half', $fields[0]['width']);
        $this->assertNull($fields[0]['visible_when']);
        $this->assertSame('full', $fields[1]['width']);
        $this->assertSame(['field' => 'fuel_type', 'is' => 'Diesel'], $fields[1]['visible_when']);
        $this->assertSame('half', $fields[2]['width'], 'an unknown width falls back rather than reaching the client');
        $this->assertNull($fields[2]['visible_when'], 'an unparseable condition is dropped, not guessed');
    }

    /**
     * The whole point of a conditional field: it must not be able to reject a
     * form the requester was never shown it on.
     */
    public function test_a_hidden_required_field_is_not_enforced(): void
    {
        $type = new PettyCashRequisitionType([
            'recipient_mode' => 'single',
            'request_fields' => [
                ['key' => 'fuel_type', 'type' => 'select', 'options' => ['Petrol', 'Diesel']],
                ['key' => 'odometer', 'type' => 'number', 'required' => true,
                    'visible_when' => ['field' => 'fuel_type', 'is' => 'Diesel']],
            ],
        ]);

        $result = app(RequisitionSchemaService::class)->validate($type, [
            'payee_name' => 'Alex',
            'custom_fields' => ['fuel_type' => 'Petrol', 'odometer' => 12345],
        ]);

        $this->assertSame(
            ['fuel_type' => 'Petrol'],
            $result['custom_fields'],
            'a hidden answer is not part of the contract and must not be retained',
        );
    }

    /** …and it must be enforced once the condition that reveals it is met. */
    public function test_a_visible_required_field_is_enforced(): void
    {
        $type = new PettyCashRequisitionType([
            'recipient_mode' => 'single',
            'request_fields' => [
                ['key' => 'fuel_type', 'type' => 'select', 'options' => ['Petrol', 'Diesel']],
                ['key' => 'odometer', 'type' => 'number', 'required' => true,
                    'visible_when' => ['field' => 'fuel_type', 'is' => 'Diesel']],
            ],
        ]);

        $this->expectException(ValidationException::class);
        app(RequisitionSchemaService::class)->validate($type, [
            'payee_name' => 'Alex',
            'custom_fields' => ['fuel_type' => 'Diesel'],
        ]);
    }

    /** A stored v2 document takes over completely from the v1 columns. */
    public function test_a_stored_document_wins_over_the_legacy_columns(): void
    {
        $type = new PettyCashRequisitionType([
            'recipient_mode' => 'single',
            'request_fields' => [['key' => 'legacy_field', 'type' => 'text']],
            'schema' => [
                'sections' => [
                    ['key' => 'brief', 'title' => 'Brief', 'fields' => [['key' => 'scope', 'type' => 'text']]],
                ],
                'line_fields' => [['key' => 'unit_cost', 'type' => 'money']],
            ],
        ]);

        $document = $type->schemaDocument();

        $this->assertSame(['brief'], array_column($document['sections'], 'key'));
        $this->assertNull($document['sections'][0]['builtin'], 'a pure schema panel needs no builtin');
        $this->assertSame('scope', $document['sections'][0]['fields'][0]['key']);
        $this->assertSame('unit_cost', $document['line_fields'][0]['key']);
        $this->assertSame(['scope'], array_column($type->allRequestFields(), 'key'));
        $this->assertSame(
            ['unit_cost'],
            array_column($type->definition()['item_fields'], 'key'),
            'the snapshot compatibility mirror must come from the resolved document, not stale v1 columns',
        );
    }

    public function test_a_hidden_section_neither_requires_nor_retains_its_fields(): void
    {
        $type = new PettyCashRequisitionType([
            'recipient_mode' => 'single',
            'schema' => [
                'sections' => [
                    ['key' => 'request', 'title' => 'Request', 'fields' => [
                        ['key' => 'travel_mode', 'type' => 'select', 'options' => ['Road', 'Rail']],
                    ]],
                    ['key' => 'road', 'title' => 'Road details',
                        'visible_when' => ['field' => 'travel_mode', 'is' => 'Road'],
                        'fields' => [['key' => 'vehicle', 'type' => 'text', 'required' => true]]],
                ],
            ],
        ]);

        $result = app(RequisitionSchemaService::class)->validate($type, [
            'payee_name' => 'Alex',
            'custom_fields' => ['travel_mode' => 'Rail', 'vehicle' => 'stale answer'],
        ]);

        $this->assertSame(['travel_mode' => 'Rail'], $result['custom_fields']);
    }

    public function test_line_field_visibility_is_evaluated_for_each_line(): void
    {
        $type = new PettyCashRequisitionType([
            'recipient_mode' => 'per_item',
            'item_fields' => [
                ['key' => 'travel_mode', 'type' => 'select', 'options' => ['Road', 'Rail']],
                ['key' => 'toll', 'type' => 'money', 'required' => true,
                    'visible_when' => ['field' => 'travel_mode', 'is' => 'Road']],
            ],
        ]);

        $result = app(RequisitionSchemaService::class)->validate($type, [
            'items' => [
                ['payee_name' => 'Rail recipient', 'details' => ['travel_mode' => 'Rail', 'toll' => 500]],
                ['payee_name' => 'Road recipient', 'details' => ['travel_mode' => 'Road', 'toll' => 300]],
            ],
        ]);

        $this->assertSame([
            ['travel_mode' => 'Rail'],
            ['travel_mode' => 'Road', 'toll' => 300],
        ], $result['item_details']);
    }

    public function test_a_visible_required_line_field_is_enforced_for_that_line(): void
    {
        $type = new PettyCashRequisitionType([
            'recipient_mode' => 'per_item',
            'item_fields' => [
                ['key' => 'travel_mode', 'type' => 'select', 'options' => ['Road', 'Rail']],
                ['key' => 'toll', 'type' => 'money', 'required' => true,
                    'visible_when' => ['field' => 'travel_mode', 'is' => 'Road']],
            ],
        ]);

        $this->expectException(ValidationException::class);
        app(RequisitionSchemaService::class)->validate($type, [
            'items' => [[
                'payee_name' => 'Road recipient',
                'details' => ['travel_mode' => 'Road'],
            ]],
        ]);
    }

    /** A snapshot must be self-describing even after its live type changes. */
    public function test_a_type_definition_stamps_the_schema_version_at_the_snapshot_boundary(): void
    {
        $definition = (new PettyCashRequisitionType([
            'code' => 'travel',
            'name' => 'Travel',
            'recipient_mode' => 'single',
        ]))->definition();

        $this->assertSame(PettyCashRequisitionType::SCHEMA_VERSION, $definition['schema_version']);
        $this->assertSame(PettyCashRequisitionType::SCHEMA_VERSION, $definition['schema']['schema_version']);
    }

    /**
     * A picker stores the reference and the label it was chosen under, so the
     * frozen snapshot stays readable after the referenced record changes.
     */
    public function test_a_project_picker_keeps_the_label_beside_the_reference(): void
    {
        $type = new PettyCashRequisitionType([
            'recipient_mode' => 'single',
            'request_fields' => [
                ['key' => 'charge_to', 'type' => 'project_picker', 'required' => true],
            ],
        ]);

        $result = app(RequisitionSchemaService::class)->validate($type, [
            'payee_name' => 'Alex',
            'custom_fields' => [
                'charge_to' => ['id' => 7, 'kind' => 'enquiry', 'label' => 'Nairobi stand build'],
            ],
        ]);

        $this->assertSame(
            ['id' => 7, 'kind' => 'enquiry', 'label' => 'Nairobi stand build'],
            $result['custom_fields']['charge_to']
        );
    }

    /** A picker answer without its reference is not an answer. */
    public function test_a_required_picker_rejects_a_label_with_no_reference(): void
    {
        $type = new PettyCashRequisitionType([
            'recipient_mode' => 'single',
            'request_fields' => [
                ['key' => 'charge_to', 'type' => 'project_picker', 'required' => true],
            ],
        ]);

        $this->expectException(ValidationException::class);
        app(RequisitionSchemaService::class)->validate($type, [
            'payee_name' => 'Alex',
            'custom_fields' => ['charge_to' => ['label' => 'Typed but never chosen']],
        ]);
    }

    /** The pickers are offered to the builder; file is deliberately still not. */
    public function test_the_field_type_list_covers_the_pickers_but_not_attachments(): void
    {
        $this->assertContains('employee_picker', PettyCashRequisitionType::FIELD_TYPES);
        $this->assertContains('project_picker', PettyCashRequisitionType::FIELD_TYPES);
        $this->assertNotContains(
            'file',
            PettyCashRequisitionType::FIELD_TYPES,
            'petty cash has no attachment storage yet, so the builder must not offer it'
        );
    }
}
