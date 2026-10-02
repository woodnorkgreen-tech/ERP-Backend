<?php

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CashReportingFixtures;
use Tests\TestCase;

class CashFlowReadinessTest extends TestCase
{
    use RefreshDatabase, CashReportingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCashReporting();
    }

    public function test_cash_flow_readiness_requires_report_permission(): void
    {
        $this->actingAs($this->outsider, 'sanctum')->getJson('/api/finance/reports/cash-flow-readiness')->assertForbidden();
    }

    public function test_cash_flow_is_explicitly_unsupported_and_never_populated_from_movements(): void
    {
        $this->cashJournal('2026-09-15', [['1010', 'debit', '500.00'], ['2100', 'credit', '500.00']]);
        $data = $this->actingAs($this->reader, 'sanctum')->getJson('/api/finance/reports/cash-flow-readiness')->assertOk()->json('data.cash_flow_statement');
        $this->assertFalse($data['supported']);
        $this->assertSame('NOT_SUPPORTED', $data['status']);
        $this->assertNull($data['method']);
        $this->assertStringContainsString('no approved', $data['reason']);
        $this->assertArrayNotHasKey('operating', $data);
        $this->assertArrayNotHasKey('investing', $data);
        $this->assertArrayNotHasKey('financing', $data);
        $this->assertArrayNotHasKey('cash_inflows', $data);
    }
}
