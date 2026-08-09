<?php

namespace Tests\Feature\CostCollector;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CostVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $reporter;
    private User $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);

        foreach ([
            Permissions::FINANCE_COSTS_READ,
            Permissions::FINANCE_COSTS_VERIFY,
            Permissions::FINANCE_COSTS_REVERSE,
        ] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->reporter = User::factory()->create(['is_active' => true]);
        $this->verifier = User::factory()->create(['is_active' => true]);
        $this->verifier->givePermissionTo([
            Permissions::FINANCE_COSTS_READ,
            Permissions::FINANCE_COSTS_VERIFY,
            Permissions::FINANCE_COSTS_REVERSE,
        ]);
    }

    private function line(array $overrides = []): CostLine
    {
        return CostLine::create(array_merge([
            'ref' => 'CL-' . str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT),
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_SUBMITTED,
            'job_number' => 'WNG-TEST-001',
            'amount' => '11600.00', 'tax_amount' => '0.00',
            'net_amount' => '11600.00', 'base_net_amount' => '11600.00',
            'fx_rate' => '1',
            'submitted_by_user_id' => $this->reporter->id,
        ], $overrides));
    }

    public function test_the_queue_shows_open_items_and_never_budget_lines(): void
    {
        $this->line();
        $this->line(['status' => CostLine::STATUS_QUERIED]);
        $this->line(['status' => CostLine::STATUS_VERIFIED]);
        $this->line(['nature' => CostLine::NATURE_PLANNED, 'status' => CostLine::STATUS_VERIFIED]);

        $this->actingAs($this->verifier, 'sanctum')
            ->getJson('/api/costs/verification')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.awaiting', 2);
    }

    public function test_verifying_records_who_and_when(): void
    {
        $line = $this->line();

        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', CostLine::STATUS_VERIFIED);

        $line->refresh();
        $this->assertSame($this->verifier->id, $line->verified_by);
        $this->assertNotNull($line->verified_at);
    }

    public function test_nobody_may_verify_a_cost_they_reported_themselves(): void
    {
        $line = $this->line(['submitted_by_user_id' => $this->verifier->id]);

        // The verifier holds every permission — separation of duties is a
        // property of the record, not of the user.
        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/verify")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['verified_by']);

        $this->assertSame(CostLine::STATUS_SUBMITTED, $line->fresh()->status);
    }

    public function test_finance_splits_the_tax_at_verification(): void
    {
        $line = $this->line();
        $vatId = DB::table('vat_treatments')->insertGetId([
            'code' => 'STD16-REC', 'name' => 'Standard 16%', 'rate_percent' => 16,
            'is_recoverable' => true, 'requires_etims' => true,
            'effective_from' => '2020-01-01', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/verify", [
                'tax_amount' => 1600, 'vat_treatment_id' => $vatId,
            ])
            ->assertOk();

        $line->refresh();
        // Gross is untouched; project cost becomes the net.
        $this->assertSame('11600.00', $line->amount);
        $this->assertSame('1600.00', $line->tax_amount);
        $this->assertSame('10000.00', $line->net_amount);
        $this->assertSame($vatId, $line->vat_treatment_id);
    }

    public function test_tax_above_the_receipt_amount_is_refused(): void
    {
        $line = $this->line();

        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/verify", ['tax_amount' => 99999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tax_amount']);
    }

    public function test_a_query_goes_back_and_can_return(): void
    {
        $line = $this->line();

        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/query", ['note' => 'Receipt is unreadable.'])
            ->assertOk()
            ->assertJsonPath('data.status', CostLine::STATUS_QUERIED)
            ->assertJsonPath('data.query_note', 'Receipt is unreadable.');

        // The person who reported it can answer without any special right.
        $this->actingAs($this->reporter, 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/resubmit")
            ->assertOk()
            ->assertJsonPath('data.status', CostLine::STATUS_SUBMITTED);
    }

    public function test_reversing_stops_a_cost_counting(): void
    {
        $line = $this->line(['status' => CostLine::STATUS_VERIFIED]);

        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/reverse", ['reason' => 'Duplicate of CL-0000012.'])
            ->assertOk()
            ->assertJsonPath('data.status', CostLine::STATUS_REVERSED);

        $this->assertSame(0, CostLine::counting()->count());
    }

    public function test_a_posted_cost_must_be_reversed_by_journal_instead(): void
    {
        $line = $this->line(['status' => CostLine::STATUS_VERIFIED, 'posted_at' => now()]);

        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/reverse", ['reason' => 'Wrong project.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_illegal_transitions_are_refused(): void
    {
        $line = $this->line(['status' => CostLine::STATUS_REJECTED]);

        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/verify")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_verification_requires_the_permission(): void
    {
        $line = $this->line();

        $this->actingAs($this->reporter, 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/verify")
            ->assertForbidden();

        $this->actingAs($this->reporter, 'sanctum')
            ->getJson('/api/costs/verification')
            ->assertForbidden();
    }
}
