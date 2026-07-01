<?php

namespace Tests\Feature\Overtime;

use App\Models\User;
use App\Modules\HR\Exceptions\OvertimeStateException;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LedgerEntry;
use App\Modules\HR\Models\OTEntry;
use App\Modules\HR\Services\OvertimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The money path: approving overtime credits the tamper-evident ledger exactly once, and a
 * credited entry can only be unwound through a reversal (a compensating entry), never a
 * re-credit or a delete. These tests guard those invariants end-to-end against the database.
 */
class OvertimeLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;
    private OvertimeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'name'     => 'HR Officer',
            'email'    => 'hr.officer@test.local',
            'password' => bcrypt('secret'),
        ]));

        $dept = Department::create(['name' => 'Production']);
        $this->employee = Employee::create([
            'employee_id'   => 'EMP-TEST-1',
            'first_name'    => 'Test',
            'last_name'     => 'Worker',
            'department_id' => $dept->id,
            'position'      => 'Technician',
            'hire_date'     => now()->subYear()->toDateString(),
            'status'        => 'active',
        ]);

        $this->service = app(OvertimeService::class);
    }

    /** A supervisor-reviewed entry ready for HR's final sign-off. */
    private function reviewedEntry(float $hours = 3): OTEntry
    {
        return OTEntry::create([
            'employee_id'            => $this->employee->id,
            'work_date'              => now()->toDateString(),
            'start_time'             => '18:00',
            'end_time'               => '21:00',
            'hours'                  => $hours,
            'status'                 => 'under_review',
            'submitted_by'           => auth()->id(),
            'supervisor_approved_by' => auth()->id(),
            'supervisor_approved_at' => now(),
        ]);
    }

    public function test_hr_approval_credits_the_ledger_exactly_once(): void
    {
        $entry = $this->reviewedEntry(3);

        $this->service->hrApprove($entry);

        $this->assertSame('done', $entry->fresh()->status);
        $this->assertEqualsWithDelta(3.0, (float) $this->employee->fresh()->ot_balance, 0.001);
        $this->assertSame(1, LedgerEntry::where('ot_entry_id', $entry->id)->count());
    }

    public function test_re_approving_a_credited_entry_is_blocked_and_does_not_double_credit(): void
    {
        $entry = $this->reviewedEntry(3);
        $this->service->hrApprove($entry);

        $this->expectException(OvertimeStateException::class);

        try {
            $this->service->hrApprove($entry->fresh());
        } finally {
            // Balance and ledger are untouched by the rejected second attempt.
            $this->assertEqualsWithDelta(3.0, (float) $this->employee->fresh()->ot_balance, 0.001);
            $this->assertSame(1, LedgerEntry::where('ot_entry_id', $entry->id)->count());
        }
    }

    public function test_reversing_a_credit_posts_a_compensating_entry_and_restores_balance(): void
    {
        $entry = $this->reviewedEntry(4);
        $this->service->hrApprove($entry);
        $credit = LedgerEntry::where('ot_entry_id', $entry->id)->firstOrFail();

        $reversal = $this->service->reverse($credit, 'Logged against the wrong project');

        $this->assertSame('debit', $reversal->kind);
        $this->assertEqualsWithDelta(4.0, (float) $reversal->hours, 0.001);
        $this->assertSame($credit->id, $reversal->reverses_ledger_id);
        $this->assertEqualsWithDelta(0.0, (float) $this->employee->fresh()->ot_balance, 0.001);
        $this->assertSame('reversed', $entry->fresh()->status);
    }

    public function test_a_ledger_entry_cannot_be_reversed_twice(): void
    {
        $entry = $this->reviewedEntry(4);
        $this->service->hrApprove($entry);
        $credit = LedgerEntry::where('ot_entry_id', $entry->id)->firstOrFail();

        $this->service->reverse($credit, 'first reversal');

        $this->expectException(OvertimeStateException::class);
        $this->service->reverse($credit->fresh(), 'second reversal');
    }

    public function test_the_chain_hash_links_each_entry_to_the_previous_one(): void
    {
        $entry = $this->reviewedEntry(4);
        $this->service->hrApprove($entry);
        $credit = LedgerEntry::where('ot_entry_id', $entry->id)->firstOrFail();

        $reversal = $this->service->reverse($credit, 'undo');

        // The reversal's stored hash must equal a recomputation over its own fields + the
        // credit's hash — proving the chain is intact and verifiable.
        $expected = LedgerEntry::generateHash($reversal, $credit->chain_hash);
        $this->assertSame($expected, $reversal->chain_hash);
    }
}
