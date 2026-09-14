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

    public function test_statements_can_be_listed_and_prefilled(): void
    {
        $sourceId = (int) \DB::table('payment_sources')->where('code', 'PC-MAIN')->value('id');

        $csv = implode("\n", [
            'date,reference,description,debit,credit,balance',
            '2026-09-10,BANK-001,Fee,10.00,0,990.00',
        ]);

        $this->actingAs($this->financeUser, 'sanctum')
            ->post('/api/finance/reconciliation/statements/import', [
                'payment_source_id' => $sourceId,
                'period_start' => '2026-09-01',
                'period_end' => '2026-09-30',
                'opening_balance' => '1000.00',
                'closing_balance' => '990.00',
                'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
            ])
            ->assertCreated();

        $listResponse = $this->actingAs($this->financeUser, 'sanctum')
            ->getJson("/api/finance/reconciliation/statements?payment_source_id={$sourceId}")
            ->assertOk();

        $this->assertCount(1, $listResponse->json('data'));

        $prefillResponse = $this->actingAs($this->financeUser, 'sanctum')
            ->getJson("/api/finance/reconciliation/statements/prefill?payment_source_id={$sourceId}")
            ->assertOk();

        $this->assertTrue($prefillResponse->json('data.has_previous'));
        $this->assertEquals('990.00', $prefillResponse->json('data.opening_balance'));
    }

    public function test_create_and_match_posts_movement_and_matches_statement_transaction(): void
    {
        $sourceId = (int) \DB::table('payment_sources')->where('code', 'PC-MAIN')->value('id');
        $bankChargesAccount = (int) \DB::table('chart_of_accounts')->where('is_active', true)->where('account_type', 'expense')->value('id');

        $csv = implode("\n", [
            'date,reference,description,debit,credit,balance',
            '2026-09-10,BANK-CHG-99,Monthly Ledger Fee,50.00,0,950.00',
        ]);

        $import = $this->actingAs($this->financeUser, 'sanctum')
            ->post('/api/finance/reconciliation/statements/import', [
                'payment_source_id' => $sourceId,
                'period_start' => '2026-09-01',
                'period_end' => '2026-09-30',
                'opening_balance' => '1000.00',
                'closing_balance' => '950.00',
                'statement' => UploadedFile::fake()->createWithContent('statement.csv', $csv),
            ])
            ->assertCreated();

        $statementId = $import->json('data.id');
        $transactionId = $import->json('data.transactions.0.id');

        $createMatchResponse = $this->actingAs($this->financeUser, 'sanctum')
            ->postJson("/api/finance/reconciliation/statements/{$statementId}/transactions/{$transactionId}/create-and-match", [
                'offset_account_id' => $bankChargesAccount,
                'transaction_type' => 'bank_fee',
                'description' => 'Monthly Ledger Fee',
            ])
            ->assertOk();

        $this->assertEquals('matched', $createMatchResponse->json('data.match_status'));
        $this->assertNotEmpty($createMatchResponse->json('data.matches'));

        // Test unmatching
        $unmatchResponse = $this->actingAs($this->financeUser, 'sanctum')
            ->postJson("/api/finance/reconciliation/statements/{$statementId}/transactions/{$transactionId}/unmatch")
            ->assertOk();

        $this->assertEquals('unmatched', $unmatchResponse->json('data.match_status'));
    }
}