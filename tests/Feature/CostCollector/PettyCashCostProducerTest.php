<?php

namespace Tests\Feature\CostCollector;

use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashTopUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PettyCashCostProducerTest extends TestCase
{
    use RefreshDatabase;

    private PettyCashCostProducer $producer;
    private User $user;
    private int $topUpId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);

        $this->producer = app(PettyCashCostProducer::class);
        $this->user = User::factory()->create();

        // Disbursements are drawn against a float top-up; the FK is required.
        $this->topUpId = PettyCashTopUp::create([
            'amount' => 500000.00,
            'payment_method' => 'cash',
            'date_topped_up' => now()->subMonth()->toDateString(),
            'created_by' => $this->user->id,
        ])->id;
    }

    private function enquiry(string $jobNumber): int
    {
        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid() . '@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Activation', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-' . uniqid(), 'job_number' => $jobNumber,
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function disbursement(array $overrides = []): PettyCashDisbursement
    {
        return PettyCashDisbursement::create(array_merge([
            'top_up_id' => $this->topUpId,
            'amount' => 4500.00,
            'receiver' => 'Bolt',
            'account' => 'Cost of Sales:Transport & Delivery',
            'description' => 'Site transport',
            'classification' => 'operations',
            'payment_method' => 'cash',
            'status' => 'active',
            'date_disbursed' => now()->subDays(3)->toDateString(),
            'created_by' => $this->user->id,
        ], $overrides));
    }

    public function test_a_paid_disbursement_becomes_a_verified_project_cost(): void
    {
        $enquiryId = $this->enquiry('WNG-01-2026-004');
        $disbursement = $this->disbursement(['job_number' => 'WNG-01-2026-004']);

        $this->assertSame('posted', $this->producer->postFor($disbursement));

        $line = CostLine::firstOrFail();
        $this->assertSame(CostLine::NATURE_ACTUAL, $line->nature);
        // Already approved and paid; re-approving it would be ceremony.
        $this->assertSame(CostLine::STATUS_VERIFIED, $line->status);
        $this->assertSame($enquiryId, $line->project_enquiry_id);
        $this->assertSame('4500.00', $line->net_amount);
        $this->assertSame('Bolt', $line->payee_name);
        // The one classification these rows carry, kept for later mapping.
        $this->assertSame('Cost of Sales:Transport & Delivery', $line->details['legacy_account']);
    }

    public function test_the_older_slash_job_number_notation_still_finds_its_project(): void
    {
        $enquiryId = $this->enquiry('WNG-01-2026-001');

        $this->assertSame('posted', $this->producer->postFor(
            $this->disbursement(['job_number' => 'WNG/01/26/001']),
        ));

        $this->assertSame($enquiryId, CostLine::firstOrFail()->project_enquiry_id);
    }

    public function test_it_normalises_month_and_sequence_padding(): void
    {
        $this->assertSame('WNG-01-2026-001', PettyCashCostProducer::normaliseJobNumber('WNG/1/26/1'));
        $this->assertSame('WNG-12-2025-004', PettyCashCostProducer::normaliseJobNumber('WNG/12/25/04'));
        // Already-canonical numbers pass through untouched.
        $this->assertSame('WNG-08-2026-002', PettyCashCostProducer::normaliseJobNumber('wng-08-2026-002'));
    }

    public function test_overhead_coded_spend_is_not_forced_onto_a_project(): void
    {
        $this->enquiry('WNG-01-2026-004');

        // ADM codes are internal overhead, not client jobs. Attaching them to a
        // project would put overhead into that project's margin.
        $this->assertSame('skipped_unmatched', $this->producer->postFor(
            $this->disbursement(['job_number' => 'ADM014/01/26.002']),
        ));

        $this->assertSame(0, CostLine::count());
    }

    public function test_a_disbursement_with_no_job_number_is_skipped(): void
    {
        $this->assertSame('skipped_no_job', $this->producer->postFor($this->disbursement(['job_number' => null])));
        $this->assertSame(0, CostLine::count());
    }

    public function test_a_voided_payment_never_becomes_a_cost(): void
    {
        $this->enquiry('WNG-01-2026-004');

        // The cash ledger already carries a reversing entry; mirroring the void
        // here would post a cost that was explicitly undone.
        $this->assertSame('skipped_inactive', $this->producer->postFor(
            $this->disbursement(['job_number' => 'WNG-01-2026-004', 'status' => 'voided']),
        ));

        $this->assertSame(0, CostLine::count());
    }

    public function test_rerunning_the_backfill_posts_nothing_twice(): void
    {
        $this->enquiry('WNG-01-2026-004');
        $this->disbursement(['job_number' => 'WNG-01-2026-004']);
        $this->disbursement(['job_number' => 'WNG-01-2026-004', 'amount' => 900]);

        $first = $this->producer->backfill();
        $second = $this->producer->backfill();

        $this->assertSame(2, $first['posted']);
        $this->assertSame(2, CostLine::count());
        $this->assertSame(2, $second['posted']);   // returns the existing lines
        $this->assertSame(2, CostLine::count());
    }

    public function test_backfilled_costs_appear_in_the_project_cost_account(): void
    {
        $enquiryId = $this->enquiry('WNG-01-2026-004');
        $this->disbursement(['job_number' => 'WNG-01-2026-004', 'amount' => 4500]);

        $this->producer->backfill();

        // Unbudgeted, because nothing links it to a planned line — which is the
        // honest reading of a payment made before budgets were projected.
        $line = CostLine::firstOrFail();
        $this->assertTrue($line->isUnbudgeted());
        $this->assertSame($enquiryId, $line->project_enquiry_id);
    }
}
