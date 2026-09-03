<?php

namespace Tests\Feature\CostCollector;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Services\JournalPostingService;
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
        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);

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

    public function test_a_super_admin_still_needs_a_reason_to_verify_their_own_cost(): void
    {
        $line = $this->line(['submitted_by_user_id' => $this->superAdmin()->id]);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/verify")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['override_reason']);

        $this->assertSame(CostLine::STATUS_SUBMITTED, $line->fresh()->status);

        // A keystroke is not a reason. The shape check refuses it before the
        // service is ever reached.
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/verify", ['override_reason' => 'ok'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['override_reason']);

        $this->assertSame(CostLine::STATUS_SUBMITTED, $line->fresh()->status);
    }

    public function test_a_super_admin_may_verify_their_own_cost_and_the_override_is_recorded(): void
    {
        $admin = $this->superAdmin();
        $line = $this->line(['submitted_by_user_id' => $admin->id]);
        $reason = 'Sole finance staffer on duty over the weekend close.';

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/verify", ['override_reason' => $reason])
            ->assertOk()
            ->assertJsonPath('data.status', CostLine::STATUS_VERIFIED);

        $this->assertSame(CostLine::STATUS_VERIFIED, $line->fresh()->status);

        // The point of allowing it at all is that it leaves a trail.
        $audit = DB::table('hr_audit_logs')
            ->where('action', 'cost_self_verification_override')
            ->where('model_id', $line->id)
            ->first();

        $this->assertNotNull($audit, 'The self-verification was not recorded.');
        $this->assertSame($admin->id, (int) $audit->user_id);
        $this->assertSame($reason, json_decode($audit->context, true)['reason']);
    }

    public function test_the_override_never_applies_to_someone_elses_cost(): void
    {
        // A reason supplied where none is needed must not become a way to skip
        // anything — this is an ordinary verification and stays one.
        $line = $this->line();

        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/verify", [
                'override_reason' => 'Reason supplied but not applicable here.',
            ])
            ->assertOk();

        $this->assertDatabaseMissing('hr_audit_logs', [
            'action' => 'cost_self_verification_override',
            'model_id' => $line->id,
        ]);
    }

    private function superAdmin(): User
    {
        return $this->admin ??= tap(
            User::factory()->create(['is_active' => true]),
            fn (User $user) => $user->assignRole(
                \Spatie\Permission\Models\Role::findOrCreate('Super Admin', 'web'),
            ),
        );
    }

    private ?User $admin = null;

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
                // A recoverable, eTIMS-bearing treatment is a claim against
                // KRA, and CostTaxPricer will not post one without the
                // reference it is matched on.
                'etims_invoice_no' => 'ETIMS-0001', 'supplier_pin' => 'P051234567X',
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
            ->postJson("/api/costs/verification/{$line->id}/resubmit", [
                'response' => 'A clearer receipt has now been attached.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', CostLine::STATUS_SUBMITTED)
            ->assertJsonPath('data.latest_query_response.response', 'A clearer receipt has now been attached.');
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

    public function test_a_posted_cost_creates_a_compensating_journal_when_reversed(): void
    {
        $line = $this->line(['status' => CostLine::STATUS_VERIFIED]);
        $original = app(JournalPostingService::class)->postCostLine($line);

        $this->actingAs($this->verifier, 'sanctum')
            ->postJson("/api/costs/verification/{$line->id}/reverse", ['reason' => 'Wrong project.'])
            ->assertOk()
            ->assertJsonPath('data.status', CostLine::STATUS_REVERSED);

        $reversal = \App\Modules\Finance\Models\JournalEntry::where('reversal_of_id', $original->id)
            ->with('lines')->firstOrFail();

        $this->assertTrue($reversal->isBalanced());
        $this->assertSame('credit', $reversal->lines->firstWhere('account_id', $original->lines[0]->account_id)->entry_type);
        $this->assertSame('reversed', $original->fresh()->status);
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

    /**
     * The race that left a rejected cost with a posted journal behind it.
     *
     * `verify()` re-read under a row lock; `query`, `reject`, `reverse` and
     * `resubmit` read status off the route-bound model and wrote over it
     * unconditionally. Two requests arriving together therefore both saw
     * `submitted`, and whichever wrote last won regardless of what the other
     * had already done to the ledger.
     *
     * A stale in-memory model is exactly what the loser of that race holds, so
     * it is what these assert against.
     */
    public function test_a_stale_reject_cannot_overwrite_a_completed_verification(): void
    {
        $line = $this->line();
        $stale = CostLine::findOrFail($line->id);

        $service = $this->app->make(\App\Modules\Finance\CostCollector\Services\CostVerificationService::class);
        $service->verify($line, $this->verifier);

        try {
            $service->reject($stale, $this->verifier, 'Raced against the verification.');
            $this->fail('A rejection was allowed to overwrite a verified cost.');
        } catch (\App\Modules\Finance\CostCollector\Exceptions\CostValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors);
        }

        $line->refresh();
        $this->assertSame(CostLine::STATUS_VERIFIED, $line->status);
        $this->assertNotNull($line->posted_at);
    }

    public function test_a_stale_query_cannot_reopen_a_verified_cost(): void
    {
        $line = $this->line();
        $stale = CostLine::findOrFail($line->id);

        $service = $this->app->make(\App\Modules\Finance\CostCollector\Services\CostVerificationService::class);
        $service->verify($line, $this->verifier);

        $this->expectException(\App\Modules\Finance\CostCollector\Exceptions\CostValidationException::class);
        $service->query($stale, $this->verifier, 'Which project was this for?');
    }

    public function test_a_cost_cannot_be_reversed_twice(): void
    {
        $line = $this->line();
        $service = $this->app->make(\App\Modules\Finance\CostCollector\Services\CostVerificationService::class);
        $service->verify($line, $this->verifier);

        $stale = CostLine::findOrFail($line->id);
        $service->reverse($line->fresh(), $this->verifier, 'Duplicate of an earlier claim.');

        $this->expectException(\App\Modules\Finance\CostCollector\Exceptions\CostValidationException::class);
        $service->reverse($stale, $this->verifier, 'Reversing it a second time.');
    }
}
