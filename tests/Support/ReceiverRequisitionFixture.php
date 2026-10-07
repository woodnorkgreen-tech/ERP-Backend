<?php

namespace Tests\Support;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Services\RequisitionControlProjection;
use App\Modules\Finance\PettyCash\Services\RequisitionDisbursementService;
use App\Modules\Finance\PettyCash\Services\RequisitionVerificationService;
use App\Modules\HR\Models\Employee;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * Report 75R-A's worked example: one requisition for KES 75,000 with four lines
 * and three receivers, one of each kind.
 *
 *   Steve   (Employee)                  Site facilitation 15,000 + Casual support 5,000
 *   Timothy (other approved recipient)  Transport 30,000
 *   Winnie  (Supplier)                  Materials 25,000
 *
 * It is created, verified by its responsible verifier and approved, the way the
 * real workflow leaves it: nothing about payment is pre-arranged.
 */
trait ReceiverRequisitionFixture
{
    protected User $creator;
    protected User $verifier;
    protected User $approver;
    protected User $finance;
    protected PettyCashRequisition $requisition;
    protected int $paymentSourceId;
    protected int $expenseCodeId;
    protected int $departmentId;
    /** Steve's own login: the one receiver who can confirm and account for himself. */
    protected User $steveUser;
    protected int $secondSourceId;
    protected int $projectExpenseCodeId;

    protected function seedFinanceReferenceData(): void
    {
        foreach (['FinanceDimensionSeeder', 'AccountingPeriodSeeder', 'ChartOfAccountSeeder', 'PaymentSourceSeeder', 'ExpenseCodeSeeder'] as $seeder) {
            $this->seed('App\\Modules\\Finance\\Database\\Seeders\\'.$seeder);
        }
    }

    protected function createReceiverRequisition(array $overrides = []): PettyCashRequisition
    {
        foreach ([Permissions::FINANCE_PETTY_CASH_CREATE, Permissions::FINANCE_PETTY_CASH_UPDATE,
            Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS, Permissions::FINANCE_PAYMENTS_REVERSE] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $suffix = Str::lower(Str::random(6));
        $this->creator = User::factory()->create(['is_active' => true, 'name' => 'Rita Creator']);
        $this->verifier = User::factory()->create(['is_active' => true, 'name' => 'Winnie Verifier']);
        $this->verifier->givePermissionTo(RequisitionVerificationService::PERMISSION);
        // Approval and payment are separate permissions held by separate people.
        $this->approver = User::factory()->create(['is_active' => true, 'name' => 'Finance Approver']);
        $this->approver->givePermissionTo(Permissions::FINANCE_PETTY_CASH_UPDATE);
        $this->finance = User::factory()->create(['is_active' => true, 'name' => 'Finance Cashier']);
        $this->finance->givePermissionTo([Permissions::FINANCE_PETTY_CASH_CREATE, Permissions::FINANCE_PETTY_CASH_VIEW_REPORTS]);

        $this->departmentId = DB::table('departments')->insertGetId(['name' => '75RA Operations '.$suffix, 'created_at' => now(), 'updated_at' => now()]);
        $this->expenseCodeId = (int) DB::table('expense_codes')->where('job_id_rule', 'not_allowed')->where('is_active', true)->value('id');
        $this->paymentSourceId = (int) DB::table('payment_sources')->where('type', 'bank')->whereNotNull('gl_account_id')->value('id');

        $steve = Employee::create(['employee_id' => 'RA-'.$suffix, 'first_name' => 'Steve', 'last_name' => 'Otieno',
            'department_id' => $this->departmentId, 'position' => 'Site lead', 'hire_date' => '2025-01-01', 'status' => 'active']);
        $this->steveUser = User::factory()->create(['is_active' => true, 'name' => 'Steve Otieno', 'employee_id' => $steve->id]);
        $this->secondSourceId = (int) DB::table('payment_sources')->whereNotNull('gl_account_id')->where('id', '!=', $this->paymentSourceId)
            ->whereIn('type', ['bank', 'mobile_money'])->orderBy('id')->value('id');
        $winnie = Supplier::create(['supplier_name' => 'Winnie Supplies', 'contact_person' => 'Winnie',
            'phone' => '0700000000', 'email' => "winnie-{$suffix}@example.test"]);

        $this->requisition = PettyCashRequisition::create(array_merge([
            'requisition_number' => PettyCashRequisition::generateRequisitionNumber(),
            'user_id' => $this->creator->id, 'responsible_verifier_id' => $this->verifier->id,
            'department_id' => $this->departmentId, 'category' => 'Operations', 'purpose' => 'NCBA Event',
            'total_amount' => '75000.00', 'status' => 'pending',
        ], $overrides));

        $this->requisition->items()->createMany([
            ['description' => 'Site facilitation', 'amount' => '15000.00', 'payee_id' => $steve->id],
            ['description' => 'Casual support', 'amount' => '5000.00', 'payee_id' => $steve->id],
            ['description' => 'Transport', 'amount' => '30000.00', 'payee_name' => 'Timothy Mwangi', 'other_recipient_reference' => 'ID-22334455'],
            ['description' => 'Materials', 'amount' => '25000.00', 'supplier_id' => $winnie->id],
        ]);

        $verification = app(RequisitionVerificationService::class);
        $verification->submit($this->requisition, $this->creator->id);
        $verification->review($this->requisition->id, $this->verifier, 'verified', null);
        $this->requisition->refresh()->forceFill([
            'status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->approver->id,
        ])->save();

        return $this->requisition->refresh();
    }

    /** Line ids by purpose, e.g. $this->lines()['Transport']. */
    protected function lines(): array
    {
        return $this->requisition->items()->orderBy('id')->pluck('id', 'description')->all();
    }

    protected function instructions(array $itemIds, string $amount, array $overrides = []): array
    {
        return array_merge([
            'item_ids' => $itemIds, 'amount' => $amount, 'idempotency_key' => (string) Str::uuid(),
            'expense_code_id' => $this->expenseCodeId, 'payment_source_id' => $this->paymentSourceId,
            'payment_method' => 'bank_transfer', 'external_reference' => 'TRX-'.Str::upper(Str::random(8)),
            'date_disbursed' => now()->toDateString(),
        ], $overrides);
    }

    protected function payReceiver(array $itemIds, string $amount, ?User $actor = null): Payment
    {
        return app(RequisitionDisbursementService::class)
            ->pay($this->requisition->id, $actor ?? $this->finance, $this->instructions($itemIds, $amount));
    }

    protected function controls(): array
    {
        return app(RequisitionControlProjection::class)->forRequisition(PettyCashRequisition::findOrFail($this->requisition->id));
    }

    /** One receiver's row from the projection, by the start of their name. */
    protected function receiver(string $name): array
    {
        return collect($this->controls()['receivers'])->first(fn (array $r) => str_starts_with($r['name'], $name));
    }

    /** Put the requisition on a project, with a project expense type, and keep it verified. */
    protected function projectRequisition(): PettyCashRequisition
    {
        $code = \App\Modules\Finance\CostCollector\Models\ExpenseCode::create(['code' => 'TST-75RA', 'accounting_class' => 'Direct project cost',
            'expense_family' => 'Direct expenses', 'expense_type' => 'Event logistics', 'job_id_rule' => \App\Modules\Finance\CostCollector\Models\ExpenseCode::JOB_OPTIONAL,
            'cash_flow_class' => 'operating', 'default_debit_account_id' => \App\Modules\Finance\Models\ChartOfAccount::where('code', '1211')->value('id'), 'is_active' => true]);
        $type = \App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType::create(['code' => 'EVT-75RA', 'name' => 'Event logistics',
            'default_expense_code_id' => $code->id, 'is_active' => true]);
        $clientId = DB::table('clients')->insertGetId(['full_name' => 'NCBA', 'email' => 'ncba@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi', 'customer_type' => 'company', 'lead_source' => 'test',
            'preferred_contact' => 'email', 'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $enquiryId = DB::table('project_enquiries')->insertGetId(['date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'NCBA Event', 'contact_person' => 'Contact', 'enquiry_number' => 'ENQ-75RA', 'job_number' => 'WNG-10-2026-075',
            'created_by' => $this->creator->id, 'created_at' => now(), 'updated_at' => now()]);

        // Set behind the model: the project is part of what was verified.
        DB::table('petty_cash_requisitions')->where('id', $this->requisition->id)
            ->update(['enquiry_id' => $enquiryId, 'requisition_type_id' => $type->id]);
        $this->projectExpenseCodeId = $code->id;
        $requisition = $this->requisition->fresh();
        $requisition->forceFill(['verification_fingerprint' => app(\App\Modules\Finance\PettyCash\Services\RequisitionVerificationService::class)
            ->fingerprint($requisition)])->saveQuietly();

        return $this->requisition = $requisition->fresh();
    }
}
