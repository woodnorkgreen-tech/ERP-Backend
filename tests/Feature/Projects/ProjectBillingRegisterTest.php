<?php

namespace Tests\Feature\Projects;

use App\Constants\EnquiryConstants;
use App\Constants\Permissions;
use App\Models\EnquiryPayment;
use App\Models\ProjectEnquiry;
use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Models\PaymentSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The project-billing register: what it can be narrowed to, and what each of its
 * rows may be released into production.
 *
 * Two properties are asserted here, and both were broken before:
 *
 *  1. A slice is chosen by the server. The rows used to be picked in the browser
 *     from whichever page happened to be loaded, while the tab's count came from
 *     the whole book — so "Deposit shortfall (12)" sat above an empty list on any
 *     page but the last.
 *  2. A row carries the release authority for the person reading it, so the
 *     register can offer releasing a project — including overriding the deposit
 *     gate with a reason — without repeating the rule that governs it.
 */
class ProjectBillingRegisterTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;

    private User $releaser;

    private User $overrider;

    private User $cashier;

    private User $approver;

    private PaymentSource $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 8)->startOfDay());
        $this->seed(FinanceReferenceSeeder::class);

        foreach ([
            Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_RECORD, Permissions::FINANCE_RECEIVABLES_VERIFY,
            Permissions::FINANCE_RECEIVABLES_RELEASE, Permissions::FINANCE_RECEIVABLES_OVERRIDE,
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->reader = $this->user(Permissions::FINANCE_RECEIVABLES_READ);
        $this->releaser = $this->user(Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_RELEASE);
        $this->overrider = $this->user(Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_RELEASE, Permissions::FINANCE_RECEIVABLES_OVERRIDE);
        $this->cashier = $this->user(Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_RECORD);
        $this->approver = $this->user(Permissions::FINANCE_RECEIVABLES_READ, Permissions::FINANCE_RECEIVABLES_VERIFY);
        $this->bank = PaymentSource::where('type', 'bank')->whereNotNull('gl_account_id')->where('is_active', true)->firstOrFail();
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    /** A receivable project: agreed price, deposit target, and no money yet. */
    private function project(string $client = 'Acme Events', float $quote = 1000000, ?User $officer = null): ProjectEnquiry
    {
        $enquiry = ProjectEnquiry::create([
            'date_received' => '2026-09-01', 'expected_delivery_date' => '2026-09-30',
            'client_id' => Client::factory()->create(['company_name' => $client, 'full_name' => $client])->id,
            'title' => "Stand for {$client}", 'description' => 'Billing register test', 'priority' => EnquiryConstants::PRIORITY_MEDIUM,
            'status' => 'awaiting_deposit', 'contact_person' => 'Jane Test',
            'enquiry_number' => 'ENQ-REG-'.uniqid(), 'job_number' => 'JOB-REG-'.uniqid(),
            'created_by' => $this->reader->id, 'project_officer_id' => $officer?->id,
            'selected_workflow_tasks' => ['design'], 'workflow_preset_type' => 'external_project',
        ]);

        DB::table('quote_approvals')->insert([
            'task_id' => 0, 'enquiry_id' => $enquiry->id, 'approval_status' => 'approved', 'approved_by' => $this->reader->id,
            'approval_date' => '2026-09-01', 'quote_amount' => $quote, 'quote_data' => json_encode(['grandTotal' => $quote]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $enquiry;
    }

    private function verifiedReceipt(ProjectEnquiry $enquiry, float $amount): void
    {
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/projects/enquiries/{$enquiry->id}/payments", [
            'amount' => $amount, 'received_amount' => $amount, 'payment_date' => '2026-09-08', 'payment_method' => 'bank_transfer',
            'payment_source_id' => $this->bank->id, 'transaction_reference' => 'FT-'.uniqid(),
        ])->assertSuccessful();

        $id = (int) EnquiryPayment::where('project_enquiry_id', $enquiry->id)->latest('id')->value('id');
        $this->actingAs($this->approver, 'sanctum')
            ->postJson("/api/projects/enquiries/{$enquiry->id}/payments/{$id}/verify")->assertOk();
    }

    /**
     * A project whose deposit is met and which production has NOT started.
     *
     * Verifying the receipt that meets the gate releases production on the spot,
     * so the only honest way into this state is money that arrived against a
     * target set afterwards — which is what lowering the deposit % does.
     */
    private function readyToStart(string $client, float $quote = 1000000, float $received = 500000, float $target = 40): ProjectEnquiry
    {
        $enquiry = $this->project($client, $quote);
        $this->verifiedReceipt($enquiry, $received);
        $enquiry->update(['mobilization_threshold_percentage' => $target]);

        return $enquiry->fresh();
    }

    private function register(User $user, string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson("/api/projects/enquiries?view=receivables&per_page=50{$query}");
    }

    private function summary(User $user, string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user, 'sanctum')->getJson("/api/projects/receivables/summary{$query}");
    }

    private function ids(\Illuminate\Testing\TestResponse $response): array
    {
        return collect($response->assertOk()->json('data.data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_a_slice_is_the_server_s_answer_and_its_count_labels_the_same_rows(): void
    {
        $awaiting = $this->project('Awaiting Co');
        $this->verifiedReceipt($awaiting, 100000);                        // 10% against a 70% target

        $ready = $this->readyToStart('Ready Co');                           // deposit met, production not started

        $releasedEarly = $this->project('Override Co');
        $this->verifiedReceipt($releasedEarly, 100000);
        $this->actingAs($this->overrider, 'sanctum')
            ->postJson("/api/projects/enquiries/{$releasedEarly->id}/release", ['notes' => 'MD approved start'])
            ->assertOk();

        // The register can find the override, and the count above it is the count
        // of what it found.
        $shortfall = $this->register($this->reader, '&tab=shortfall');
        $this->assertSame([$releasedEarly->id], $this->ids($shortfall));
        $this->assertSame(1, $shortfall->json('data.meta.total'));
        $this->assertSame($shortfall->json('data.meta.total'), $this->summary($this->reader)->json('data.tabs.shortfall'));

        $this->assertSame([$ready->id], $this->ids($this->register($this->reader, '&tab=mobilized')));
        $this->assertSame(1, $this->summary($this->reader)->json('data.tabs.mobilized'));

        // A slice is a filter, not a replacement book: "all" is still the whole
        // register, and "needs action" is the project still short of its deposit.
        $this->assertCount(3, $this->ids($this->register($this->reader)));
        $this->assertSame([$awaiting->id], $this->ids($this->register($this->reader, '&tab=action')));
    }

    public function test_an_unknown_slice_is_refused_rather_than_silently_ignored(): void
    {
        $this->project();

        $this->register($this->reader, '&tab=nonsense')->assertStatus(422);
        // The whole book is a real answer, not a missing one.
        $this->register($this->reader, '&tab=all')->assertOk();
    }

    public function test_narrowing_moves_the_tab_counts_but_not_the_headline_money(): void
    {
        $officer = $this->user();
        $this->verifiedReceipt($this->project('Acme Events'), 100000);
        $this->verifiedReceipt($this->project('Other Co'), 100000);
        $mine = $this->project('Acme Events');
        $mine->update(['project_officer_id' => $officer->id]);
        $this->verifiedReceipt($mine, 100000);

        $wholeBook = $this->summary($this->reader)->assertOk();
        $this->assertSame(3, $wholeBook->json('data.tabs.all'));

        // Filtered to one officer: the counts describe the rows on screen...
        $narrowed = $this->summary($this->reader, '?project_officer_id='.$officer->id)->assertOk();
        $this->assertSame(1, $narrowed->json('data.tabs.all'));
        $this->assertSame([$mine->id], $this->ids($this->register($this->reader, '&project_officer_id='.$officer->id)));

        // ...while the money still describes every project with billing, because
        // a headline that shrank with the search box would be a lie.
        $this->assertSame($wholeBook->json('data.stats.total_project_value'), $narrowed->json('data.stats.total_project_value'));
        $this->assertSame(3000000.0, (float) $wholeBook->json('data.stats.total_project_value'));
    }

    public function test_the_register_offers_the_people_in_the_book_to_narrow_to(): void
    {
        $officer = User::factory()->create(['is_active' => true, 'name' => 'Pat Officer']);
        $project = $this->project('Acme Events');
        $project->update(['project_officer_id' => $officer->id]);
        $this->project('Second Co');

        $summary = $this->summary($this->reader)->assertOk();

        $this->assertSame(['Acme Events', 'Second Co'], collect($summary->json('data.clients'))->pluck('name')->all());
        $this->assertSame(['Pat Officer'], collect($summary->json('data.officers'))->pluck('name')->all());
        // A company known by its trading name is the label, not the contact.
        $this->assertSame([$project->id], $this->ids($this->register($this->reader, '&client_id='.$project->client_id)));
    }

    public function test_each_row_carries_the_release_authority_of_the_person_reading_it(): void
    {
        $ready = $this->readyToStart('Ready Co');
        $early = $this->project('Early Co');
        $this->verifiedReceipt($early, 100000);                          // 10% of a 70% target

        $row = fn (User $user, int $id) => collect($this->register($user)->json('data.data'))->firstWhere('id', $id);

        // The deposit is met: release permission alone is enough.
        $this->assertTrue($row($this->releaser, $ready->id)['actions']['release']['allowed']);
        // Below the gate it takes the override permission, and the row says so
        // rather than the register deciding for itself.
        $this->assertFalse($row($this->releaser, $early->id)['actions']['release']['allowed']);
        $this->assertStringContainsString('override permission', $row($this->releaser, $early->id)['actions']['release']['reason']);
        $this->assertTrue($row($this->overrider, $early->id)['actions']['release']['allowed']);

        // A reader with neither is told why, on every row.
        foreach ([$ready, $early] as $project) {
            $this->assertFalse($row($this->reader, $project->id)['actions']['release']['allowed']);
            $this->assertStringContainsString('release projects into production', $row($this->reader, $project->id)['actions']['release']['reason']);
        }

        // And the gate itself travels with the row, so the register can say how
        // far short a project is without asking again.
        $this->assertSame(70, (int) $row($this->reader, $early->id)['finance_summary']['threshold_percentage']);
        $this->assertFalse((bool) $row($this->reader, $early->id)['finance_summary']['is_threshold_met']);
        $this->assertTrue((bool) $row($this->reader, $ready->id)['finance_summary']['is_threshold_met']);

        // Released projects offer nothing further.
        $this->actingAs($this->overrider, 'sanctum')
            ->postJson("/api/projects/enquiries/{$early->id}/release", ['notes' => 'MD approved start'])->assertOk();
        $this->assertStringContainsString('already been released', $row($this->overrider, $early->id)['actions']['release']['reason']);
    }

    public function test_the_deposit_gate_can_be_overridden_only_with_a_reason(): void
    {
        $early = $this->project('Early Co');
        $this->verifiedReceipt($early, 100000);

        // A releaser without the override permission cannot do it at all.
        $this->actingAs($this->releaser, 'sanctum')
            ->postJson("/api/projects/enquiries/{$early->id}/release", ['notes' => 'Client is good for it'])->assertForbidden();

        // With it, the reason is not optional.
        $this->actingAs($this->overrider, 'sanctum')
            ->postJson("/api/projects/enquiries/{$early->id}/release", ['notes' => ''])->assertStatus(422);

        $this->actingAs($this->overrider, 'sanctum')
            ->postJson("/api/projects/enquiries/{$early->id}/release", ['notes' => 'MD approved start, deposit on Friday'])->assertOk();

        $this->assertDatabaseHas('governance_audit_logs', [
            'project_enquiry_id' => $early->id,
            'gate_type' => 'financial',
        ]);
    }
}
