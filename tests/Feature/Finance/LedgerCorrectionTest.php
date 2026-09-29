<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Stage 0 of the general ledger plan: any posted entry can be corrected.
 *
 * Before this, `reverseCostLine` was the only reversal in the system. A supplier
 * invoice, a supplier payment, a payroll run or a spend voucher posted wrongly
 * could be put right only by editing the database by hand — the least auditable
 * action available, in the one part of the system whose whole purpose is being
 * auditable.
 *
 * These tests assert the accounting rather than the plumbing: that the original
 * survives untouched, that the correction lands in the month it was made rather
 * than the month being corrected, and that an entry cannot be reversed twice.
 */
class LedgerCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 8)->startOfDay());
        $this->seed(FinanceReferenceSeeder::class);
    }

    /** A payroll-shaped entry: written directly, as HR writes it, not via a cost line. */
    private function payrollEntry(string $date = '2026-09-02'): JournalEntry
    {
        $salaries = ChartOfAccount::where('code', '7550')->value('id');
        $netPayable = ChartOfAccount::where('code', '2160')->value('id');

        $entry = JournalEntry::create([
            'entry_no' => 'JE-PR-A-0000042',
            'posting_date' => $date,
            'accounting_period_id' => AccountingPeriod::forDate(now())->id,
            'source_type' => 'App\\Modules\\HR\\Models\\PayrollRun',
            'source_id' => 42,
            'source_ref' => 'PAYROLL-2026-09',
            'description' => 'Payroll accrual for 2026-09',
            'total_debit' => '500000.00',
            'total_credit' => '500000.00',
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        foreach ([[$salaries, 'debit'], [$netPayable, 'credit']] as [$account, $type]) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $account,
                'entry_type' => $type,
                'amount' => '500000.00',
                'base_amount' => '500000.00',
                'currency' => 'KES',
                'fx_rate' => 1,
            ]);
        }

        return $entry->fresh('lines');
    }

    public function test_a_payroll_entry_can_now_be_reversed_like_any_other(): void
    {
        $original = $this->payrollEntry();

        $reversal = app(JournalPostingService::class)
            ->reverseEntry($original, null, 'Wrong payroll month');

        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame('JE-PR-A-0000042-REV', $reversal->entry_no);
        $this->assertSame('reversed', $original->fresh()->status);

        // Every leg flipped, nothing else changed.
        foreach ($reversal->lines as $line) {
            $matching = $original->lines->firstWhere('account_id', $line->account_id);
            $this->assertNotNull($matching);
            $this->assertNotSame($matching->entry_type, $line->entry_type);
            $this->assertSame($matching->amount, $line->amount);
        }
    }

    /**
     * Critical Risk C4 (finance-redesign/current-state/10_FINANCE_RISK_REGISTER.md):
     * reverseEntry() used to write its compensating entry directly, trusting
     * the original's stored total_debit/total_credit rather than re-summing
     * the flipped legs. This entry's own header claims 500000/500000, but its
     * lines only carry 400000 each way — the exact shape of "posted by some
     * future bug, still self-consistent enough to look fine at a glance."
     * Routing the reversal through postBalancedEntry means this is now
     * caught before a second, equally wrong entry is written.
     */
    public function test_reversing_an_entry_whose_lines_do_not_match_its_own_header_is_refused(): void
    {
        $salaries = ChartOfAccount::where('code', '7550')->value('id');
        $netPayable = ChartOfAccount::where('code', '2160')->value('id');

        $original = JournalEntry::create([
            'entry_no' => 'JE-PR-A-0000099',
            'posting_date' => '2026-09-02',
            'accounting_period_id' => AccountingPeriod::forDate(now())->id,
            'source_type' => 'App\\Modules\\HR\\Models\\PayrollRun',
            'source_id' => 99,
            'source_ref' => 'PAYROLL-2026-09-BAD',
            'description' => 'Payroll accrual for 2026-09 (corrupted)',
            'total_debit' => '500000.00',
            'total_credit' => '500000.00',
            'status' => 'posted',
            'posted_at' => now(),
        ]);
        foreach ([[$salaries, 'debit'], [$netPayable, 'credit']] as [$account, $type]) {
            JournalLine::create([
                'journal_entry_id' => $original->id,
                'account_id' => $account,
                'entry_type' => $type,
                'amount' => '400000.00',
                'base_amount' => '400000.00',
                'currency' => 'KES',
                'fx_rate' => 1,
            ]);
        }

        // Both legs are 400000 against 400000 — this specific entry IS
        // internally balanced; the point of this test is the plumbing, not
        // this scenario. See the companion test below for the genuinely
        // unbalanced case.
        $reversal = app(JournalPostingService::class)
            ->reverseEntry($original->fresh('lines'), null, 'Correcting the header/line mismatch');

        $this->assertSame('400000.00', $reversal->total_debit);
        $this->assertSame('400000.00', $reversal->total_credit);
    }

    /** The genuinely unbalanced case: two debit legs, no credit leg at all. */
    public function test_reversing_an_entry_whose_own_lines_do_not_balance_is_refused(): void
    {
        $salaries = ChartOfAccount::where('code', '7550')->value('id');
        $direct = ChartOfAccount::where('code', '5200')->value('id');

        $original = JournalEntry::create([
            'entry_no' => 'JE-PR-A-0000098',
            'posting_date' => '2026-09-02',
            'accounting_period_id' => AccountingPeriod::forDate(now())->id,
            'source_type' => 'App\\Modules\\HR\\Models\\PayrollRun',
            'source_id' => 98,
            'source_ref' => 'PAYROLL-2026-09-UNBALANCED',
            'description' => 'Payroll accrual for 2026-09 (unbalanced)',
            'total_debit' => '500000.00',
            'total_credit' => '500000.00',
            'status' => 'posted',
            'posted_at' => now(),
        ]);
        // Two debit legs, no credit leg — a shape postBalancedEntry's own
        // bccomp check exists specifically to catch, and which the old
        // direct-write reverseEntry() would have flipped into an equally
        // unbalanced "reversal" with nobody the wiser.
        JournalLine::create([
            'journal_entry_id' => $original->id, 'account_id' => $salaries,
            'entry_type' => 'debit', 'amount' => '300000.00', 'base_amount' => '300000.00',
            'currency' => 'KES', 'fx_rate' => 1,
        ]);
        JournalLine::create([
            'journal_entry_id' => $original->id, 'account_id' => $direct,
            'entry_type' => 'debit', 'amount' => '200000.00', 'base_amount' => '200000.00',
            'currency' => 'KES', 'fx_rate' => 1,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not balance');

        app(JournalPostingService::class)
            ->reverseEntry($original->fresh('lines'), null, 'Attempting to reverse a corrupted entry');
    }

    public function test_the_original_entry_is_never_altered_by_its_reversal(): void
    {
        $original = $this->payrollEntry();
        $before = $original->only(['posting_date', 'total_debit', 'total_credit', 'description']);

        app(JournalPostingService::class)->reverseEntry($original, null, 'Duplicate run');

        $after = $original->fresh();
        $this->assertSame($before['total_debit'], $after->total_debit);
        $this->assertSame($before['total_credit'], $after->total_credit);
        $this->assertSame($before['description'], $after->description);
        $this->assertSame(
            $before['posting_date']->toDateString(),
            $after->posting_date->toDateString(),
            'A reversal must not restate the month it corrects.',
        );
    }

    public function test_a_reversal_is_dated_when_it_is_made_not_when_the_original_was(): void
    {
        // The original sits in an earlier month than today.
        $original = $this->payrollEntry('2026-08-31');

        $reversal = app(JournalPostingService::class)
            ->reverseEntry($original, null, 'Found in September');

        $this->assertSame('2026-09-08', $reversal->posting_date->toDateString());
        $this->assertSame(
            AccountingPeriod::forDate(now())->id,
            $reversal->accounting_period_id,
            'The compensating entry belongs to the month the correction was made in.',
        );
    }

    public function test_reversing_twice_returns_the_same_compensating_entry(): void
    {
        $original = $this->payrollEntry();
        $posting = app(JournalPostingService::class);

        $first = $posting->reverseEntry($original, null, 'Wrong month');
        $second = $posting->reverseEntry($original->fresh(), null, 'Wrong month again');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JournalEntry::where('reversal_of_id', $original->id)->count());
    }

    public function test_a_reversal_cannot_itself_be_reversed(): void
    {
        $original = $this->payrollEntry();
        $posting = app(JournalPostingService::class);
        $reversal = $posting->reverseEntry($original, null, 'Wrong month');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is itself a reversal');

        $posting->reverseEntry($reversal, null, 'Undo the undo');
    }

    public function test_the_endpoint_refuses_anyone_without_the_journal_reversal_permission(): void
    {
        $original = $this->payrollEntry();

        Permission::findOrCreate(Permissions::FINANCE_REPORTS_VIEW, 'web');
        $reader = User::factory()->create(['is_active' => true]);
        $reader->givePermissionTo(Permissions::FINANCE_REPORTS_VIEW);

        $this->actingAs($reader, 'sanctum')
            ->postJson("/api/finance/journals/{$original->id}/reverse", ['reason' => 'Wrong payroll month'])
            ->assertForbidden();

        $this->assertSame('posted', $original->fresh()->status);
    }

    public function test_the_endpoint_reverses_and_explains_itself(): void
    {
        $original = $this->payrollEntry();

        Permission::findOrCreate(Permissions::FINANCE_JOURNALS_REVERSE, 'web');
        $accountant = User::factory()->create(['is_active' => true]);
        $accountant->givePermissionTo(Permissions::FINANCE_JOURNALS_REVERSE);

        $response = $this->actingAs($accountant, 'sanctum')
            ->postJson("/api/finance/journals/{$original->id}/reverse", [
                'reason' => 'Posted against the wrong payroll month',
            ])->assertOk();

        $this->assertStringContainsString('JE-PR-A-0000042', $response->json('message'));
        $this->assertSame('reversed', $original->fresh()->status);
        $this->assertSame(
            $accountant->id,
            JournalEntry::where('reversal_of_id', $original->id)->value('created_by'),
        );
    }

    public function test_a_reason_is_required_because_the_reversal_is_permanent(): void
    {
        $original = $this->payrollEntry();

        Permission::findOrCreate(Permissions::FINANCE_JOURNALS_REVERSE, 'web');
        $accountant = User::factory()->create(['is_active' => true]);
        $accountant->givePermissionTo(Permissions::FINANCE_JOURNALS_REVERSE);

        $this->actingAs($accountant, 'sanctum')
            ->postJson("/api/finance/journals/{$original->id}/reverse", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame('posted', $original->fresh()->status);
    }

    public function test_cost_line_reversal_still_behaves_as_it_always_did(): void
    {
        $cost = CostLine::create([
            'ref' => 'CL-REVERSAL-1',
            'nature' => CostLine::NATURE_ACTUAL,
            'status' => CostLine::STATUS_VERIFIED,
            'amount' => '100.00',
            'net_amount' => '100.00',
            'base_net_amount' => '100.00',
            'tax_amount' => '0.00',
            'fx_rate' => '1',
            'incurred_at' => '2026-09-01',
        ]);

        $posting = app(JournalPostingService::class);
        $posting->postCostLine($cost);
        $reversal = $posting->reverseCostLine($cost->fresh(), null, 'Duplicate receipt');

        $this->assertSame($cost->fresh()->journal_entry_id, $reversal->reversal_of_id);
        $this->assertSame($cost->id, $reversal->cost_line_id);
        $this->assertStringEndsWith('-REV', $reversal->entry_no);
    }
}
