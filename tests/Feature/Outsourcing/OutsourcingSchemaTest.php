<?php

namespace Tests\Feature\Outsourcing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OutsourcingSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMNS = [
        'outsourced_partner_name',
        'outsourced_reason',
        'outsourced_reason_note',
        'outsourced_by',
        'outsourced_at',
    ];

    public function test_design_items_and_print_jobs_have_outsourcing_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('design_items', self::COLUMNS));
        $this->assertTrue(Schema::hasColumns('print_jobs', self::COLUMNS));
    }
}
