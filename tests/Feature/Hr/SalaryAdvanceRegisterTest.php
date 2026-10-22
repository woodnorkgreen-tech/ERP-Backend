<?php

namespace Tests\Feature\Hr;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\HRAuditLog;
use App\Modules\HR\Models\SalaryAdvanceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The salary-advance register HR filters and hands to Finance as Excel.
 */
class SalaryAdvanceRegisterTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;
    private Department $workshop;
    private Department $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hr = $this->userWith(Permissions::HR_MANAGE_PAYROLL, 'Wanjiku HR');
        $this->workshop = Department::create(['name' => 'Workshop']);
        $this->office = Department::create(['name' => 'Office']);
    }

    private function userWith(?string $permission, string $name): User
    {
        $user = User::create(['name' => $name, 'email' => uniqid().'@test.local', 'password' => bcrypt('x'), 'is_active' => true]);
        if ($permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    private function employee(string $first, Department $department, array $extra = []): Employee
    {
        return Employee::create(array_merge([
            'employee_id' => 'WNG'.random_int(100, 999), 'first_name' => $first, 'last_name' => 'Otieno',
            'department_id' => $department->id, 'position' => 'Technician', 'hire_date' => '2025-01-01',
            'status' => 'active', 'salary' => 40000, 'payment_method' => 'bank',
            'bank_name' => 'KCB', 'bank_code' => '01', 'bank_branch' => 'Moi Avenue', 'account_number' => '0012345678901',
            'id_number' => '23456789', 'phone' => '0712345678',
        ], $extra));
    }

    private function advance(Employee $employee, string $status, float $amount, string $month, array $extra = []): SalaryAdvanceRequest
    {
        return SalaryAdvanceRequest::create(array_merge([
            'employee_id' => $employee->id, 'amount' => $amount, 'reason' => 'School fees',
            'target_payroll_month' => $month, 'status' => $status,
        ], $extra));
    }

    /** A pending, an approved-unpaid, a paid-outstanding and a rejected advance. */
    private function seedRegister(): array
    {
        $amina = $this->employee('Amina', $this->workshop);
        $brian = $this->employee('Brian', $this->office, ['account_number' => null]);
        $pending = $this->advance($amina, 'pending', 5000, '2026-10');
        $approved = $this->advance($brian, 'approved', 8000, '2026-11');
        HRAuditLog::create(['user_id' => $this->hr->id, 'employee_id' => $brian->id, 'action' => 'salary_advance_approved',
            'model_type' => 'SalaryAdvanceRequest', 'model_id' => $approved->id, 'message' => 'approved',
            'context' => ['split_installments' => true, 'monthly_installment' => 4000]]);
        $paymentId = DB::table('payments')->insertGetId([
            'amount' => 6000, 'payee_name' => 'Amina Otieno', 'account' => 'Cash', 'description' => 'Salary advance',
            'classification' => 'admin', 'created_by' => $this->hr->id, 'external_reference' => 'MPESA-QX1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $paid = $this->advance($amina, 'disbursed', 6000, '2026-09', ['payment_id' => $paymentId, 'paid_at' => now(), 'amount_recovered' => 2000]);
        $rejected = $this->advance($brian, 'rejected', 20000, '2026-10', ['hr_remarks' => 'Exceeds cap']);

        return compact('amina', 'brian', 'pending', 'approved', 'paid', 'rejected');
    }

    public function test_only_hr_or_finance_can_see_or_download_the_register(): void
    {
        $this->seedRegister();
        $staff = $this->userWith(null, 'Any employee');

        $this->actingAs($staff)->getJson('/api/hr/advances')->assertForbidden();
        $this->actingAs($staff)->get('/api/hr/advances/export')->assertForbidden();
        $this->actingAs($this->userWith(Permissions::FINANCE_SPEND_VOUCHERS_POST, 'Finance'))->getJson('/api/hr/advances')->assertOk();
        $this->actingAs($this->hr)->getJson('/api/hr/advances')->assertOk();
    }

    public function test_only_hr_can_decide_on_an_advance(): void
    {
        ['pending' => $pending] = $this->seedRegister();
        $finance = $this->userWith(Permissions::FINANCE_SPEND_VOUCHERS_POST, 'Finance');

        $this->actingAs($finance)->postJson("/api/hr/advances/{$pending->id}/approve")->assertForbidden();
        $this->actingAs($finance)->postJson("/api/hr/advances/{$pending->id}/reject", ['hr_remarks' => 'no'])->assertForbidden();
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_filters_narrow_the_list_and_the_totals_follow(): void
    {
        $seed = $this->seedRegister();
        $this->actingAs($this->hr);

        $all = $this->getJson('/api/hr/advances')->assertOk();
        $this->assertSame(4, $all->json('data.total'));
        $this->assertEquals(39000, $all->json('summary.amount'));

        $due = $this->getJson('/api/hr/advances?status=approved')->assertOk();
        $this->assertSame([$seed['approved']->id], collect($due->json('data.data'))->pluck('id')->all());
        $this->assertEquals(8000, $due->json('summary.awaiting_payout_amount'));

        $outstanding = $this->getJson('/api/hr/advances?status=outstanding')->assertOk();
        $this->assertSame([$seed['paid']->id], collect($outstanding->json('data.data'))->pluck('id')->all());
        $this->assertEquals(4000, $outstanding->json('summary.outstanding_amount'));

        $this->assertSame(2, $this->getJson('/api/hr/advances?status=pending,rejected')->json('data.total'));
        $this->assertSame(2, $this->getJson("/api/hr/advances?department_id={$this->workshop->id}")->json('data.total'));
        $this->assertSame(2, $this->getJson('/api/hr/advances?search=Brian')->json('data.total'));
        $this->assertSame(3, $this->getJson('/api/hr/advances?month_from=2026-10')->json('data.total'));
        $this->assertSame(2, $this->getJson('/api/hr/advances?month_from=2026-10&month_to=2026-10')->json('data.total'));
        // Older screens asked for "disbursed".
        $this->assertSame(1, $this->getJson('/api/hr/advances?status=disbursed')->json('data.total'));
    }

    public function test_the_workbook_gives_finance_what_it_needs_to_pay(): void
    {
        $this->seedRegister();
        $response = $this->actingAs($this->hr)->get('/api/hr/advances/export?status=approved,outstanding,pending,rejected');
        $response->assertOk();
        $this->assertStringContainsString('salary-advances_', $response->headers->get('content-disposition'));

        $path = tempnam(sys_get_temp_dir(), 'adv').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);

        $this->assertSame(['For disbursement', 'Register', 'Summary'], $book->getSheetNames());

        // 1. For disbursement: only the approved, unpaid advance, with its payment details.
        $pay = $book->getSheetByName('For disbursement');
        $header = $this->headerRow($pay, 'Ref');
        $columns = $this->columns($pay, $header);
        $this->assertStringContainsString('Status: ', (string) $pay->getCell('A2')->getValue());
        $this->assertSame('Brian Otieno', $pay->getCell($columns['Employee'].($header + 1))->getValue());
        $this->assertEquals(8000, $pay->getCell($columns['Amount (KES)'].($header + 1))->getValue());
        $this->assertSame('Missing account number', $pay->getCell($columns['Check before paying'].($header + 1))->getValue());
        $this->assertSame('Wanjiku HR', $pay->getCell($columns['Approved by'].($header + 1))->getValue());
        $this->assertStringStartsWith('TOTAL (1)', (string) $pay->getCell('A'.($header + 2))->getValue());
        // The total is a live formula with its value already calculated, so no viewer shows 0.
        $this->assertEquals(8000, $pay->getCell($columns['Amount (KES)'].($header + 2))->getOldCalculatedValue());
        // Every data cell wraps, and the row is tall enough for its wrapped text.
        $this->assertTrue($pay->getStyle($columns['Narration'].($header + 1))->getAlignment()->getWrapText());
        $this->assertGreaterThan(15, $pay->getRowDimension($header + 1)->getRowHeight());
        // Phone and account numbers stay exact text.
        $this->assertSame('s', $pay->getCell($columns['Phone'].($header + 1))->getDataType());
        $this->assertSame('0712345678', $pay->getCell($columns['Phone'].($header + 1))->getValue());
        foreach (['ID No.', 'Department', 'Approved on'] as $dropped) {
            $this->assertArrayNotHasKey($dropped, $columns);
        }
        foreach (['Paid on', 'Payment ref.', 'Paid by'] as $financeColumn) {
            $this->assertArrayHasKey($financeColumn, $columns);
            $this->assertSame('FFF7D6', $pay->getStyle($columns[$financeColumn].($header + 1))->getFill()->getStartColor()->getRGB());
        }
        // Header styling, freeze and number formats sit on the real header and data rows.
        $this->assertTrue($pay->getStyle('A'.$header)->getFont()->getBold());
        $this->assertSame('A'.($header + 1), $pay->getFreezePane());
        $this->assertSame('#,##0.00', $pay->getStyle($columns['Amount (KES)'].($header + 1))->getNumberFormat()->getFormatCode());
        $this->assertSame('FDE2E2', $pay->getStyle('A'.($header + 1))->getFill()->getStartColor()->getRGB(), 'missing bank details are flagged');

        // 2. Register: every application, numbers stay numbers and IDs stay text.
        $register = $book->getSheetByName('Register');
        $header = $this->headerRow($register, 'Ref');
        $columns = $this->columns($register, $header);
        $this->assertSame(4, $register->getHighestDataRow() - $header - 1, 'four rows plus a totals row');
        $recovered = collect(range($header + 1, $header + 4))->map(fn ($row) => $register->getCell($columns['Recovered'].$row)->getValue());
        $this->assertContains(0.0, $recovered->all(), 'a zero is written as 0.00, not left blank');
        foreach (['ID No.', 'KRA PIN', 'Monthly salary', 'HR remarks'] as $dropped) {
            $this->assertArrayNotHasKey($dropped, $columns, "{$dropped} is not part of the register");
        }
        $this->assertSame('#,##0.00', $register->getStyle($columns['Advance (KES)'].($header + 1))->getNumberFormat()->getFormatCode());
        $plans = collect(range($header + 1, $header + 4))->map(fn ($row) => $register->getCell($columns['Repayment plan'].$row)->getValue());
        $this->assertContains('KES 4,000.00/month × 2', $plans->all());

        // 3. Summary by status and department.
        $summary = $book->getSheetByName('Summary');
        $labels = collect($summary->toArray())->pluck(1)->filter()->all();
        $this->assertContains('Approved — awaiting payout', $labels);
        $this->assertContains('Workshop', $labels);

        // The download itself is on the record.
        $this->assertDatabaseHas('hr_audit_logs', ['action' => 'salary_advance_register_exported', 'user_id' => $this->hr->id]);
        @unlink($path);
    }

    public function test_an_empty_selection_still_downloads_with_an_explanation(): void
    {
        $this->seedRegister();
        $response = $this->actingAs($this->hr)->get('/api/hr/advances/export?search=nobody-matches');
        $path = tempnam(sys_get_temp_dir(), 'adv').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $values = collect(IOFactory::load($path)->getSheetByName('For disbursement')->toArray())->flatten()->filter()->implode(' ');
        $this->assertStringContainsString('No approved advances are waiting for payout', $values);
        @unlink($path);
    }

    public function test_the_pdf_is_a_branded_document_for_finance_sign_off(): void
    {
        $this->seedRegister();
        $staff = $this->userWith(null, 'Any employee');
        $this->actingAs($staff)->get('/api/hr/advances/export/pdf')->assertForbidden();

        $response = $this->actingAs($this->hr)->get('/api/hr/advances/export/pdf?status=approved,outstanding');
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('salary-advances_', $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertDatabaseHas('hr_audit_logs', ['action' => 'salary_advance_register_exported', 'user_id' => $this->hr->id]);

        // What the page says, from the same rows as the Excel.
        $register = app(\App\Modules\HR\Services\SalaryAdvanceRegister::class);
        $filters = $register->filters(\Illuminate\Http\Request::create('/', 'GET', ['status' => 'approved,outstanding']));
        $document = new \App\Modules\HR\Support\Pdf\Documents\SalaryAdvanceRegisterDocument($register->rows($filters), ['Status: Approved — awaiting payout'], 'Wanjiku HR');
        $html = view('pdf.hr.salary_advance_register', $document->data())->render();

        $this->assertStringContainsString('Salary Advance Register', $html);
        $this->assertStringContainsString('Woodnork Green Ltd', $html, 'WNG letterhead and footer');
        $this->assertStringContainsString('Brian Otieno', $html);
        $this->assertStringContainsString('TOTAL TO DISBURSE (1)', $html);
        $this->assertStringContainsString('8,000.00', $html);
        $this->assertStringContainsString('Missing account number', $html);
        $this->assertStringContainsString('Received by (Finance)', $html);
        $this->assertStringNotContainsString('Exceeds cap', $html, 'rejected advances are outside these filters');
    }

    public function test_nobody_decides_on_their_own_advance_and_past_self_approvals_are_flagged(): void
    {
        ['amina' => $amina] = $this->seedRegister();
        $own = $this->advance($amina, 'pending', 3000, '2026-12');
        $aminaHr = $this->userWith(Permissions::HR_MANAGE_PAYROLL, 'Amina Otieno');
        $aminaHr->forceFill(['employee_id' => $amina->id])->save();

        $this->actingAs($aminaHr->fresh())->postJson("/api/hr/advances/{$own->id}/approve")->assertForbidden();
        $this->actingAs($aminaHr->fresh())->postJson("/api/hr/advances/{$own->id}/reject", ['hr_remarks' => 'x'])->assertForbidden();
        $this->assertSame('pending', $own->fresh()->status);

        // An approval recorded before this rule is surfaced, not hidden.
        $own->update(['status' => 'approved']);
        HRAuditLog::create(['user_id' => $aminaHr->id, 'employee_id' => $amina->id, 'action' => 'salary_advance_approved',
            'model_type' => 'SalaryAdvanceRequest', 'model_id' => $own->id, 'message' => 'self']);
        $rows = app(\App\Modules\HR\Services\SalaryAdvanceRegister::class)->rows(['status' => ['approved']]);
        $row = $rows->firstWhere('reference', \App\Modules\HR\Services\SalaryAdvanceRegister::reference($own));
        $this->assertTrue($row['self_approved']);
        $this->assertSame('Approved by the applicant', $row['checks']);
    }

    private function headerRow($sheet, string $first): int
    {
        foreach ($sheet->getRowIterator() as $row) {
            if ($sheet->getCell('A'.$row->getRowIndex())->getValue() === $first) return $row->getRowIndex();
        }
        $this->fail("No header row starting with {$first}");
    }

    private function columns($sheet, int $header): array
    {
        $map = [];
        foreach ($sheet->getRowIterator($header, $header)->current()->getCellIterator() as $cell) {
            if ($cell->getValue() !== null) $map[$cell->getValue()] = $cell->getColumn();
        }

        return $map;
    }
}
