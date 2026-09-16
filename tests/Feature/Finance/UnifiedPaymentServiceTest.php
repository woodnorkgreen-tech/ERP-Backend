<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Exceptions\InvalidPaymentSourceException;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\Services\UnifiedPaymentService;
use App\Modules\Finance\Support\PaymentData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test suite for UnifiedPaymentService
 * Phase 4 of Finance Architecture Redesign
 */
class UnifiedPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private UnifiedPaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(UnifiedPaymentService::class);
    }

    public function test_rejects_payable_payment_sources(): void
    {
        $payableSource = PaymentSource::factory()->create([
            'type' => 'payable',
            'can_make_payment' => false,
            'is_active' => true,
        ]);

        $this->expectException(InvalidPaymentSourceException::class);

        $this->service->createPayment(
            PaymentData::fromArray([
                'payment_source_id' => $payableSource->id,
                'payment_method' => 'bank_transfer',
                'amount' => 100000,
                'payee_name' => 'Test Supplier',
                'payee_type' => 'supplier',
            ])
        );
    }

    public function test_rejects_inactive_payment_sources(): void
    {
        $inactiveSource = PaymentSource::factory()->create([
            'type' => 'bank',
            'can_make_payment' => true,
            'is_active' => false,
        ]);

        $this->expectException(InvalidPaymentSourceException::class);

        $this->service->createPayment(
            PaymentData::fromArray([
                'payment_source_id' => $inactiveSource->id,
                'payment_method' => 'bank_transfer',
                'amount' => 100000,
                'payee_name' => 'Test Supplier',
                'payee_type' => 'supplier',
            ])
        );
    }

    public function test_creates_payment_with_valid_source(): void
    {
        $this->setupAccountingPeriod();
        $source = PaymentSource::factory()->create([
            'type' => 'bank',
            'can_make_payment' => true,
            'is_active' => true,
            'gl_account_id' => 1, // Would need proper GL account setup
        ]);

        $payment = $this->service->createPayment(
            PaymentData::fromArray([
                'payment_source_id' => $source->id,
                'payment_method' => 'bank_transfer',
                'amount' => 100000,
                'payee_name' => 'Test Supplier',
                'payee_type' => 'supplier',
            ])
        );

        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertEquals('paid', $payment->status);
        $this->assertEquals(100000, $payment->amount);
        $this->assertNotNull($payment->payment_no);
    }

    public function test_voiding_payment_reverses_journal_and_updates_status(): void
    {
        $this->setupAccountingPeriod();
        $source = PaymentSource::factory()->create([
            'type' => 'bank',
            'can_make_payment' => true,
            'is_active' => true,
            'gl_account_id' => 1,
        ]);

        $payment = $this->service->createPayment(
            PaymentData::fromArray([
                'payment_source_id' => $source->id,
                'payment_method' => 'bank_transfer',
                'amount' => 100000,
                'payee_name' => 'Test Supplier',
                'payee_type' => 'supplier',
            ])
        );

        $this->service->voidPayment($payment, 'Test void reason', auth()->id() ?? 1);

        $payment->refresh();

        $this->assertEquals('voided', $payment->status);
        $this->assertEquals('Test void reason', $payment->void_reason);
        $this->assertNotNull($payment->voided_at);
    }

    public function test_payment_with_allocations_marks_cost_lines_as_settled(): void
    {
        $this->setupAccountingPeriod();
        $source = PaymentSource::factory()->create([
            'type' => 'bank',
            'can_make_payment' => true,
            'is_active' => true,
            'gl_account_id' => 1,
        ]);

        $costLine = CostLine::factory()->create([
            'nature' => CostLine::NATURE_ACCRUED,
            'status' => CostLine::STATUS_VERIFIED,
            'net_amount' => 50000,
        ]);

        $payment = $this->service->createPayment(
            PaymentData::fromArray([
                'payment_source_id' => $source->id,
                'payment_method' => 'bank_transfer',
                'amount' => 50000,
                'payee_name' => 'Test Supplier',
                'payee_type' => 'supplier',
                'allocations' => [
                    [
                        'cost_line_id' => $costLine->id,
                        'amount' => 50000,
                    ],
                ],
            ])
        );

        $costLine->refresh();

        $this->assertEquals($payment->id, $costLine->settled_by_payment_id);
    }

    public function test_voiding_payment_clears_cost_line_settlements(): void
    {
        $this->setupAccountingPeriod();
        $source = PaymentSource::factory()->create([
            'type' => 'bank',
            'can_make_payment' => true,
            'is_active' => true,
            'gl_account_id' => 1,
        ]);

        $costLine = CostLine::factory()->create([
            'nature' => CostLine::NATURE_ACCRUED,
            'status' => CostLine::STATUS_VERIFIED,
            'net_amount' => 50000,
        ]);

        $payment = $this->service->createPayment(
            PaymentData::fromArray([
                'payment_source_id' => $source->id,
                'payment_method' => 'bank_transfer',
                'amount' => 50000,
                'payee_name' => 'Test Supplier',
                'payee_type' => 'supplier',
                'allocations' => [
                    [
                        'cost_line_id' => $costLine->id,
                        'amount' => 50000,
                    ],
                ],
            ])
        );

        $this->service->voidPayment($payment, 'Test void', auth()->id() ?? 1);

        $costLine->refresh();

        $this->assertNull($costLine->settled_by_payment_id);
    }

    private function setupAccountingPeriod(): void
    {
        AccountingPeriod::create([
            'year' => now()->year,
            'month' => now()->month,
            'starts_on' => now()->startOfMonth(),
            'ends_on' => now()->endOfMonth(),
            'status' => AccountingPeriod::STATUS_OPEN,
        ]);
    }
}
