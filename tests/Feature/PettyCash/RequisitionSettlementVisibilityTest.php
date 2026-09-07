<?php

namespace Tests\Feature\PettyCash;

use App\Models\User;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\PettyCash\Models\PettyCashDisbursement;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A fund requisition has to say which float paid it.
 *
 * The payment source has been recorded on `petty_cash_disbursements` since
 * payment sources existed — the disbursement is the thing that moved the money,
 * so that is the right place for it. But the requisition register never loaded
 * the relation and offered no filter for it, so "Paid" was all it could say.
 * Whether that meant the tin or a bank account was invisible, and those are the
 * difference between a cash count that reconciles and one that does not.
 */
class RequisitionSettlementVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\App\Modules\Finance\Database\Seeders\PaymentSourceSeeder::class);

        Role::findOrCreate('Accounts', 'web');
        $this->finance = User::create([
            'name' => 'Finance Officer',
            'email' => uniqid('finance_').'@test.local',
            'password' => bcrypt('secret'),
            'is_active' => true,
        ]);
        $this->finance->assignRole('Accounts');
        Sanctum::actingAs($this->finance);
    }

    public function test_the_register_says_which_float_paid_each_request(): void
    {
        $paid = $this->requisitionPaidFrom('PC-MAIN');

        $response = $this->getJson('/api/finance/petty-cash/requisitions')->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $paid->id);

        $this->assertNotNull($row, 'The paid request must appear in the register.');
        $this->assertSame(
            'Main Petty Cash Float',
            data_get($row, 'disbursement.payment_source.name'),
            '"Paid" on its own does not say whether the money left the tin or a bank account.'
        );
    }

    public function test_the_register_can_be_filtered_to_what_came_out_of_the_tin(): void
    {
        $fromTin = $this->requisitionPaidFrom('PC-MAIN');
        $fromBank = $this->requisitionPaidFrom('BANK-MAIN');

        $ids = collect(
            $this->getJson('/api/finance/petty-cash/requisitions?payment_source_id='
                .PaymentSource::where('code', 'PC-MAIN')->value('id'))
                ->assertOk()->json('data')
        )->pluck('id');

        $this->assertTrue($ids->contains($fromTin->id));
        $this->assertFalse(
            $ids->contains($fromBank->id),
            'A request settled from the bank is not petty cash spend and must not be counted as it.'
        );
    }

    public function test_an_unpaid_request_is_found_by_the_absence_of_a_payment(): void
    {
        $unpaid = $this->requisition();
        $paid = $this->requisitionPaidFrom('PC-MAIN');

        $ids = collect(
            $this->getJson('/api/finance/petty-cash/requisitions?settlement=unpaid')
                ->assertOk()->json('data')
        )->pluck('id');

        // Unpaid is the absence of a disbursement, not a status: a request can
        // sit approved for weeks before the cash goes out.
        $this->assertTrue($ids->contains($unpaid->id));
        $this->assertFalse($ids->contains($paid->id));
    }

    private function requisition(): PettyCashRequisition
    {
        return PettyCashRequisition::create([
            'requisition_number' => 'FR-'.uniqid(),
            'user_id' => $this->finance->id,
            'department_id' => \App\Modules\HR\Models\Department::firstOrCreate(['name' => 'Production'])->id,
            'category' => 'Site meals & refreshments',
            'purpose' => 'Site refreshments',
            'total_amount' => 5000,
            'status' => 'approved',
            'requester_name' => 'Site Supervisor',
        ]);
    }

    private function requisitionPaidFrom(string $sourceCode): PettyCashRequisition
    {
        $requisition = $this->requisition();

        PettyCashDisbursement::create([
            'requisition_id' => $requisition->id,
            'payment_source_id' => PaymentSource::where('code', $sourceCode)->value('id'),
            'receiver' => 'Site Supervisor',
            'account' => 'Site refreshments',
            'classification' => 'operations',
            'amount' => 5000,
            'transaction_cost' => 0,
            'description' => 'Site refreshments',
            'date_disbursed' => now()->toDateString(),
            'payment_method' => $sourceCode === 'PC-MAIN' ? 'cash' : 'bank_transfer',
            'status' => 'active',
            'created_by' => $this->finance->id,
        ]);

        $requisition->update(['status' => 'disbursed']);

        return $requisition->fresh();
    }
}
