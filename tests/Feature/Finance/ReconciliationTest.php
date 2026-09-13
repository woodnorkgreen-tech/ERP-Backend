<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $financeUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceReferenceSeeder::class);
        Permission::findOrCreate(Permissions::FINANCE_REPORTS_VIEW, 'web');
        Permission::findOrCreate(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE, 'web');

        $this->financeUser = User::factory()->create(['is_active' => true]);
        $this->financeUser->givePermissionTo([
            Permissions::FINANCE_REPORTS_VIEW,
            Permissions::FINANCE_PAYMENT_SOURCES_MANAGE,
        ]);
    }

    public function test_statement_must_be_resolved_before_it_can_be_reconciled(): void
    {
        $csv = implode("\n", [
            'date,reference,description,debit,credit,balance',
            '2026-09-10,BANK-001,Bank charge,0,0,1000.00',
        ]);

        $response = $this->actingAs($this->financeUser, 'sanctum')
            ->post('/api/finance/reconciliation/statements/import', [
                'payment_source_id' => (int) \DB::table('payment_sources')->where('code', 'PC-MAIN')->value('id'),
                'period_start' => '2026-09-01',
                'period_end' => '2026-09-30',
                'opening_balance' => '1000.00',
                'closing_balance' => '1000.00',
                'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
            ])
            ->assertCreated();

        $statementId = $response->json('data.id');
        $transactionId = $response->json('data.transactions.0.id');

        $this->actingAs($this->financeUser, 'sanctum')
            ->postJson("/api/finance/reconciliation/statements/{$statementId}/reconcile")
            ->assertStatus(422);

        $this->actingAs($this->financeUser, 'sanctum')
            ->postJson("/api/finance/reconciliation/statements/{$statementId}/transactions/{$transactionId}/ignore")
            ->assertOk();

        $this->actingAs($this->financeUser, 'sanctum')
            ->postJson("/api/finance/reconciliation/statements/{$statementId}/reconcile")
            ->assertOk();

        $this->assertDatabaseHas('finance_reconciliation_statements', [
            'id' => $statementId,
            'status' => 'reconciled',
        ]);
    }
}