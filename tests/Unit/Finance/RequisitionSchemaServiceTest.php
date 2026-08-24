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
}
