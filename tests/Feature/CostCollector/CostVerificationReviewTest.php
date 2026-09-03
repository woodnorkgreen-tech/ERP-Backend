<?php

namespace Tests\Feature\CostCollector;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Modules\Finance\Database\Seeders\FinanceTaxSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The verification screen as a review rather than a list of amounts.
 *
 * These cover what the queue must be able to TELL a verifier and what it must
 * let them DO about it — the payee that was missing from the payload, filters and
 * aggregates that describe the same set as the rows, a tax split visible before
 * it is committed, and a resolution path for unbudgeted spend.
 */
class CostVerificationReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $reporter;
    private User $verifier;
    private int $enquiryId;
    private int $otherEnquiryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(FinanceTaxSeeder::class);

        foreach ([
            Permissions::FINANCE_COSTS_READ,
            Permissions::FINANCE_COSTS_VERIFY,
            Permissions::FINANCE_COSTS_REVERSE,
            Permissions::FINANCE_COSTS_CREATE,
        ] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->reporter = User::factory()->create(['is_active' => true]);
        $this->verifier = User::factory()->create(['is_active' => true]);
        $this->verifier->givePermissionTo([
            Permissions::FINANCE_COSTS_READ,
            Permissions::FINANCE_COSTS_VERIFY,
            Permissions::FINANCE_COSTS_REVERSE,
            Permissions::FINANCE_COSTS_CREATE,
        ]);

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => 'review@t.local', 'phone' => '0700000001',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $enquiry = function (string $number, string $job) use ($clientId) {
            return DB::table('project_enquiries')->insertGetId([
                'date_received' => now()->toDateString(), 'client_id' => $clientId,
                'title' => 'Activation ' . $job, 'contact_person' => 'Contact',
                'enquiry_number' => $number, 'job_number' => $job,
                'created_by' => $this->reporter->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        $this->enquiryId = $enquiry('ENQ-REV-001', 'WNG-REV-001');
        $this->otherEnquiryId = $enquiry('ENQ-REV-002', 'WNG-REV-002');
    }

    private function line(array $overrides = []): CostLine
    {
        return CostLine::create(array_merge([
            'ref' => 'CL-' . str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT),
            'project_enquiry_id' => $this->enquiryId,
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_SUBMITTED,
            'job_number' => 'WNG-REV-001',
            'amount' => '11600.00', 'tax_amount' => '0.00',
            'net_amount' => '11600.00', 'base_net_amount' => '11600.00',
            'fx_rate' => '1',
            'incurred_at' => now()->subDay(),
            'submitted_by_user_id' => $this->reporter->id,
        ], $overrides));
    }

    private function plannedLine(int $enquiryId, string $net, string $description): CostLine
    {
        return $this->line([
            'project_enquiry_id' => $enquiryId,
            'nature' => CostLine::NATURE_PLANNED,
            'status' => CostLine::STATUS_VERIFIED,
            'description' => $description,
            'amount' => $net, 'net_amount' => $net, 'base_net_amount' => $net,
        ]);
    }

    private function asVerifier(): self
    {
        $this->actingAs($this->verifier, 'sanctum');

        return $this;
    }

    /* ── The payload ─────────────────────────────────────────────────────── */

    public function test_the_queue_says_who_was_paid(): void
    {
        $supplierId = DB::table('suppliers')->insertGetId([
            'supplier_name' => 'Rivet Signs Ltd', 'contact_person' => 'Ann',
            'phone' => '0711000000', 'email' => 'ann@rivet.local',
            'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $supplierType = DB::table('payee_types')->where('requires_supplier_record', true)->value('id');

        $this->line([
            'payee_type_id' => $supplierType,
            'payee_id' => $supplierId,
            'payee_name' => 'Rivet Signs Ltd',
        ]);

        $this->asVerifier()
            ->getJson('/api/costs/verification')
            ->assertOk()
            ->assertJsonPath('data.0.payee.name', 'Rivet Signs Ltd')
            ->assertJsonPath('data.0.payee.is_supplier', true)
            // The payee type name comes from the reference table without a model,
            // so this also pins that the subselect scope is applied.
            ->assertJsonPath('data.0.payee.type', fn ($type) => filled($type));
    }

    public function test_the_queue_carries_the_budget_line_and_coding(): void
    {
        $planned = $this->plannedLine($this->enquiryId, '50000.00', 'Vinyl and print');

        $centreId = DB::table('cost_centres')->value('id');

        $this->line([
            'consumes_line_id' => $planned->id,
            'cost_centre_id' => $centreId,
            'budget_remaining_after' => '38400.00',
        ]);

        $this->asVerifier()
            ->getJson('/api/costs/verification')
            ->assertOk()
            ->assertJsonPath('data.0.consumes_line.description', 'Vinyl and print')
            ->assertJsonPath('data.0.consumes_line.budgeted', '50000.00')
            ->assertJsonPath('data.0.is_unbudgeted', false)
            ->assertJsonPath('data.0.budget_remaining_after', '38400.00')
            ->assertJsonPath('data.0.cost_centre', fn ($centre) => filled($centre));
    }

    public function test_the_payable_figure_is_gross_less_withholding(): void
    {
        $this->line([
            'net_amount' => '10000.00', 'tax_amount' => '1600.00', 'wht_amount' => '500.00',
        ]);

        $this->asVerifier()
            ->getJson('/api/costs/verification')
            ->assertOk()
            // 10000 + 1600 − 500. This is the credit leg `postCostLine` builds,
            // so a disagreement here means the screen and the ledger disagree.
            ->assertJsonPath('data.0.payable_amount', '11100.00');
    }

    public function test_age_is_measured_from_when_the_cost_was_incurred(): void
    {
        $this->line(['incurred_at' => now()->subDays(45)]);

        $this->asVerifier()
            ->getJson('/api/costs/verification')
            ->assertOk()
            ->assertJsonPath('data.0.age_days', 45);
    }

    /* ── Aggregates ──────────────────────────────────────────────────────── */

    public function test_the_summary_describes_the_filtered_set_not_the_system(): void
    {
        $this->line(['net_amount' => '1000.00', 'job_number' => 'WNG-REV-001']);
        $this->line(['net_amount' => '2000.00', 'job_number' => 'WNG-REV-001']);
        $this->line(['net_amount' => '9000.00', 'job_number' => 'WNG-REV-002', 'project_enquiry_id' => $this->otherEnquiryId]);

        $this->asVerifier()
            ->getJson('/api/costs/verification?job_number=WNG-REV-001')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            // The old header counted every open cost regardless of the filter, so
            // "3 awaiting" sat above two rows.
            ->assertJsonPath('meta.summary.count', 2)
            ->assertJsonPath('meta.summary.value', '3000.00')
            ->assertJsonPath('meta.awaiting', 2);
    }

    public function test_unbudgeted_value_is_reported_separately(): void
    {
        $planned = $this->plannedLine($this->enquiryId, '50000.00', 'Budgeted');

        $this->line(['net_amount' => '4000.00', 'consumes_line_id' => $planned->id]);
        $this->line(['net_amount' => '7000.00']);

        $this->asVerifier()
            ->getJson('/api/costs/verification')
            ->assertOk()
            ->assertJsonPath('meta.summary.unbudgeted_count', 1)
            ->assertJsonPath('meta.summary.unbudgeted_value', '7000.00');
    }

    public function test_ageing_buckets_split_the_queue_by_how_old_it_is(): void
    {
        $this->line(['incurred_at' => now()->subDays(2), 'net_amount' => '100.00']);
        $this->line(['incurred_at' => now()->subDays(14), 'net_amount' => '200.00']);
        $this->line(['incurred_at' => now()->subDays(90), 'net_amount' => '300.00']);

        $response = $this->asVerifier()->getJson('/api/costs/verification')->assertOk();

        $buckets = collect($response->json('meta.summary.ageing'))->keyBy('id');

        $this->assertSame(1, $buckets['current']['count']);
        $this->assertSame(1, $buckets['watch']['count']);
        $this->assertSame(1, $buckets['late']['count']);
        $this->assertSame('300.00', $buckets['late']['value']);
    }

    public function test_the_ageing_bar_still_offers_every_bucket_while_one_is_selected(): void
    {
        $this->line(['incurred_at' => now()->subDays(2)]);
        $this->line(['incurred_at' => now()->subDays(90)]);

        $response = $this->asVerifier()
            ->getJson('/api/costs/verification?age_bucket=late')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $buckets = collect($response->json('meta.summary.ageing'))->keyBy('id');

        // The bar is also the control that sets the filter. Intersecting it with
        // its own selection would zero the buckets you would click back to.
        $this->assertSame(1, $buckets['current']['count']);
        $this->assertTrue($buckets['late']['active']);
    }

    /* ── Filters ─────────────────────────────────────────────────────────── */

    public function test_the_queue_filters_on_date_and_amount_ranges(): void
    {
        $this->line(['incurred_at' => '2026-06-10 09:00:00', 'net_amount' => '500.00']);
        $this->line(['incurred_at' => '2026-07-10 09:00:00', 'net_amount' => '5000.00']);

        $this->asVerifier()
            ->getJson('/api/costs/verification?from=2026-07-01&to=2026-07-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.net_amount', '5000.00');

        $this->asVerifier()
            ->getJson('/api/costs/verification?max_amount=1000')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.net_amount', '500.00');
    }

    public function test_costs_with_nothing_attached_can_be_singled_out(): void
    {
        $this->line(['evidence' => [['key' => 'receipt', 'path' => 'costs/a.jpg']]]);
        $this->line(['evidence' => []]);

        $this->asVerifier()
            ->getJson('/api/costs/verification?has_evidence=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.evidence_count', 0);
    }

    public function test_the_queue_can_be_narrowed_to_where_a_cost_came_from(): void
    {
        $this->line();
        $this->line([
            'source_type' => 'App\\Modules\\Finance\\CostCollector\\Services\\PettyCashCostProducer',
            'source_id' => 4242,
        ]);

        $this->asVerifier()
            ->getJson('/api/costs/verification?origin=captured')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.origin', 'captured');

        $this->asVerifier()
            ->getJson('/api/costs/verification?origin=petty_cash')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.origin', 'petty_cash');
    }

    public function test_search_covers_the_payee_not_only_the_reference(): void
    {
        $this->line(['payee_name' => 'Kariuki Transporters']);
        $this->line(['payee_name' => 'Signwriters Kenya']);

        $this->asVerifier()
            ->getJson('/api/costs/verification?q=Kariuki')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payee.name', 'Kariuki Transporters');
    }

    public function test_rejected_and_reversed_costs_are_reachable(): void
    {
        $this->line(['status' => CostLine::STATUS_REJECTED]);
        $this->line(['status' => CostLine::STATUS_REVERSED]);
        $this->line();

        // Neither state appeared on any screen before, so what had been refused
        // or backed out could not be audited from the UI at all.
        $this->asVerifier()
            ->getJson('/api/costs/verification?status=rejected')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->asVerifier()
            ->getJson('/api/costs/verification?status=all')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_the_queue_sorts_by_amount_on_request(): void
    {
        $this->line(['net_amount' => '100.00']);
        $this->line(['net_amount' => '9000.00']);
        $this->line(['net_amount' => '450.00']);

        $this->asVerifier()
            ->getJson('/api/costs/verification?sort=amount&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.0.net_amount', '9000.00')
            ->assertJsonPath('data.2.net_amount', '100.00');
    }

    public function test_the_queue_paginates(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->line();
        }

        $this->asVerifier()
            ->getJson('/api/costs/verification?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5);
    }

    /* ── Tax preview ─────────────────────────────────────────────────────── */

    public function test_the_tax_preview_prices_the_split_and_the_journal(): void
    {
        $line = $this->line(['amount' => '11600.00', 'net_amount' => '11600.00']);

        $treatment = DB::table('vat_treatments')->where('code', 'STD16-REC')->first();

        $response = $this->asVerifier()
            ->getJson("/api/costs/verification/{$line->id}/tax-preview?tax_amount=1600&vat_treatment_id={$treatment->id}")
            ->assertOk()
            ->assertJsonPath('split.net_amount', '10000.00')
            ->assertJsonPath('split.tax_amount', '1600.00')
            ->assertJsonPath('split.payable', '11600.00');

        $legs = collect($response->json('legs'));

        // Mirrors JournalPostingService: Dr expense, Dr recoverable VAT, Cr cash.
        $this->assertSame('10000.00', $legs->firstWhere('label', 'Expense / project WIP')['amount']);
        $this->assertSame('1600.00', $legs->firstWhere('label', 'Input VAT recoverable')['amount']);
        $this->assertSame('11600.00', $legs->firstWhere('label', 'Cash / supplier payable')['amount']);
    }

    public function test_the_preview_offers_the_treatments_in_force_on_the_cost_date(): void
    {
        $line = $this->line();

        $response = $this->asVerifier()
            ->getJson("/api/costs/verification/{$line->id}/tax-preview")
            ->assertOk();

        $this->assertNotEmpty($response->json('options.vat_treatments'));
        $this->assertNotEmpty($response->json('options.wht_categories'));

        // Each WHT option is priced against this cost so the consequence of the
        // choice is visible before it is made.
        foreach ($response->json('options.wht_categories') as $option) {
            $this->assertArrayHasKey('would_withhold', $option);
        }
    }

    public function test_an_impossible_tax_amount_previews_as_an_error_rather_than_failing(): void
    {
        $line = $this->line(['amount' => '100.00', 'net_amount' => '100.00']);

        $this->asVerifier()
            ->getJson("/api/costs/verification/{$line->id}/tax-preview?tax_amount=500")
            ->assertOk()
            ->assertJsonPath('errors.tax_amount.0', 'Tax cannot exceed the amount on the receipt.');
    }

    public function test_what_the_preview_shows_is_what_verifying_writes(): void
    {
        $line = $this->line(['amount' => '11600.00', 'net_amount' => '11600.00']);

        $treatment = DB::table('vat_treatments')->where('code', 'STD16-REC')->first();

        $preview = $this->asVerifier()
            ->getJson("/api/costs/verification/{$line->id}/tax-preview?tax_amount=1600&vat_treatment_id={$treatment->id}")
            ->assertOk()
            ->json('split');

        $this->asVerifier()
            ->postJson("/api/costs/verification/{$line->id}/verify", [
                'tax_amount' => 1600,
                'vat_treatment_id' => $treatment->id,
                // STD16-REC claims input tax back, so the claim reference is
                // required before it can post.
                'etims_invoice_no' => 'ETIMS-0001',
                'supplier_pin' => 'P051234567X',
            ])
            ->assertOk();

        $line->refresh();

        // The preview and the commit run the same pricer. If they are ever split
        // apart again, this is the test that fails.
        $this->assertSame($preview['net_amount'], (string) $line->net_amount);
        $this->assertSame($preview['tax_amount'], (string) $line->tax_amount);
        $this->assertSame($preview['wht_amount'], (string) $line->wht_amount);
    }

    /* ── Reclassification ────────────────────────────────────────────────── */

    public function test_unbudgeted_spend_can_be_pointed_at_its_budget_line(): void
    {
        $planned = $this->plannedLine($this->enquiryId, '50000.00', 'Crew transport');
        $line = $this->line(['net_amount' => '11600.00']);

        $this->assertTrue($line->isUnbudgeted());

        $this->asVerifier()
            ->postJson("/api/costs/verification/{$line->id}/reclassify", [
                'consumes_line_id' => $planned->id,
                'reason' => 'Confirmed with the PM that this is the crew transport line.',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_unbudgeted', false)
            ->assertJsonPath('data.consumes_line.description', 'Crew transport');

        $line->refresh();

        $this->assertSame($planned->id, $line->consumes_line_id);
        // The snapshot is recomputed: the old one described a draw against a line
        // this cost is no longer charged to.
        $this->assertSame('50000.00', (string) $line->budget_remaining_before);
        $this->assertSame('38400.00', (string) $line->budget_remaining_after);
    }

    public function test_a_reclassification_is_recorded_with_its_reason(): void
    {
        $planned = $this->plannedLine($this->enquiryId, '50000.00', 'Crew transport');
        $line = $this->line();

        $this->asVerifier()
            ->postJson("/api/costs/verification/{$line->id}/reclassify", [
                'consumes_line_id' => $planned->id,
                'reason' => 'Mis-coded at capture; belongs to crew transport.',
            ])
            ->assertOk();

        $line->refresh();

        $entry = $line->capture_meta['reclassifications'][0];

        $this->assertNull($entry['from']);
        $this->assertSame($planned->id, $entry['to']);
        $this->assertSame($this->verifier->id, $entry['by']);
        $this->assertStringContainsString('Mis-coded', $entry['reason']);
    }

    public function test_a_cost_can_be_detached_from_a_budget_line(): void
    {
        $planned = $this->plannedLine($this->enquiryId, '50000.00', 'Crew transport');
        $line = $this->line(['consumes_line_id' => $planned->id]);

        $this->asVerifier()
            ->postJson("/api/costs/verification/{$line->id}/reclassify", [
                'consumes_line_id' => null,
                'reason' => 'Attached to the wrong line at capture and belongs nowhere.',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_unbudgeted', true);

        $line->refresh();

        $this->assertNull($line->consumes_line_id);
        $this->assertNull($line->budget_remaining_after);
    }

    public function test_another_projects_budget_line_is_refused(): void
    {
        $foreign = $this->plannedLine($this->otherEnquiryId, '50000.00', 'Someone elses budget');
        $line = $this->line();

        $this->asVerifier()
            ->postJson("/api/costs/verification/{$line->id}/reclassify", [
                'consumes_line_id' => $foreign->id,
                'reason' => 'Trying to move spend onto a different job entirely.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.consumes_line_id.0', 'That budget line belongs to a different project.');

        $this->assertNull($line->fresh()->consumes_line_id);
    }

    public function test_a_spend_line_cannot_be_pointed_at_another_spend_line(): void
    {
        $other = $this->line();
        $line = $this->line();

        $this->asVerifier()
            ->postJson("/api/costs/verification/{$line->id}/reclassify", [
                'consumes_line_id' => $other->id,
                'reason' => 'This is not a budget line and must be refused.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.consumes_line_id.0', 'That is not a budget line.');
    }

    public function test_a_rejected_cost_cannot_be_reclassified(): void
    {
        $planned = $this->plannedLine($this->enquiryId, '50000.00', 'Crew transport');
        $line = $this->line(['status' => CostLine::STATUS_REJECTED]);

        $this->asVerifier()
            ->postJson("/api/costs/verification/{$line->id}/reclassify", [
                'consumes_line_id' => $planned->id,
                'reason' => 'Should not be able to re-code a dead record.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'A rejected cost cannot be reclassified.');
    }

    public function test_reclassifying_to_the_same_line_is_refused(): void
    {
        $planned = $this->plannedLine($this->enquiryId, '50000.00', 'Crew transport');
        $line = $this->line(['consumes_line_id' => $planned->id]);

        $this->asVerifier()
            ->postJson("/api/costs/verification/{$line->id}/reclassify", [
                'consumes_line_id' => $planned->id,
                'reason' => 'A no-op should not create an audit entry.',
            ])
            ->assertStatus(422);

        $this->assertArrayNotHasKey('reclassifications', $line->fresh()->capture_meta ?? []);
    }

    public function test_reclassification_needs_a_real_reason(): void
    {
        $planned = $this->plannedLine($this->enquiryId, '50000.00', 'Crew transport');
        $line = $this->line();

        $this->asVerifier()
            ->postJson("/api/costs/verification/{$line->id}/reclassify", [
                'consumes_line_id' => $planned->id,
                'reason' => 'oops',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_reclassifying_requires_the_verify_permission(): void
    {
        $planned = $this->plannedLine($this->enquiryId, '50000.00', 'Crew transport');
        $line = $this->line();

        $this->actingAs($this->reporter, 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/reclassify", [
                'consumes_line_id' => $planned->id,
                'reason' => 'A reporter must not be able to re-code their own spend.',
            ])
            ->assertForbidden();
    }

    /* ── One cost, in full ───────────────────────────────────────────────── */

    public function test_a_single_cost_returns_its_whole_history(): void
    {
        $line = $this->line(['status' => CostLine::STATUS_QUERIED]);

        $this->asVerifier()
            ->postJson("/api/costs/verification/{$line->id}/resubmit", [
                'response' => 'Receipt reattached, it was the wrong photo.',
            ])
            ->assertOk();

        $response = $this->asVerifier()
            ->getJson("/api/costs/verification/{$line->id}")
            ->assertOk();

        $events = collect($response->json('history'));

        // Earlier revisions and answers were exposed only as `latest_*`, so
        // everything before the most recent one was unreachable.
        $this->assertSame('captured', $events->first()['event']);
        $this->assertNotNull($events->firstWhere('event', 'answered'));
        $this->assertStringContainsString(
            'wrong photo',
            $events->firstWhere('event', 'answered')['note'],
        );
    }

    /* ── The reporter's own view ──────────────────────────────────────────── */

    public function test_my_submissions_totals_cover_every_page(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->line(['net_amount' => '1000.00']);
        }
        $this->line(['net_amount' => '250.00', 'status' => CostLine::STATUS_VERIFIED]);

        $this->actingAs($this->reporter, 'sanctum')
            ->getJson('/api/costs?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            // Summed over everything this person reported, not over the page —
            // otherwise "reported by you" silently became "on this page".
            ->assertJsonPath('meta.summary.total.count', 5)
            ->assertJsonPath('meta.summary.total.value', '4250.00')
            ->assertJsonPath('meta.summary.submitted.count', 4)
            ->assertJsonPath('meta.summary.verified.count', 1)
            ->assertJsonPath('meta.last_page', 3);
    }
}
