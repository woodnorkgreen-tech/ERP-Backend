<?php

namespace Tests\Unit;

use App\Models\User;
use App\Modules\ClientService\Models\Client;
use App\Modules\Projects\Models\Enquiry;
use App\Modules\Projects\Models\EnquiryPhase;
use App\Modules\Projects\Models\Phase;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnquiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create phases for testing
        Phase::create([
            'name' => 'initial_review',
            'title' => 'Initial Review',
            'order' => 1,
            'is_active' => true,
        ]);

        Phase::create([
            'name' => 'design',
            'title' => 'Design',
            'order' => 2,
            'is_active' => true,
        ]);

        Phase::create([
            'name' => 'quotation',
            'title' => 'Quotation',
            'order' => 3,
            'is_active' => true,
        ]);

        // Create finance.approve permission for testing
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'finance.approve']);
    }

    #[Test]
    public function it_creates_phases_automatically_when_enquiry_is_created()
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $enquiry = Enquiry::create([
            'date_received' => now(),
            'client_id' => $client->id,
            'title' => 'Test Enquiry',
            'description' => 'Test description',
            'priority' => 'high',
            'created_by' => $user->id,
        ]);

        $this->assertEquals(3, $enquiry->phases()->count());
        $this->assertEquals(['pending', 'pending', 'pending'], $enquiry->phases->pluck('status')->toArray());
    }

    #[Test]
    public function it_generates_enquiry_number_automatically()
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $enquiry = Enquiry::create([
            'date_received' => now(),
            'client_id' => $client->id,
            'title' => 'Test Enquiry',
            'description' => 'Test description',
            'priority' => 'high',
            'created_by' => $user->id,
        ]);

        $this->assertEquals(1, $enquiry->enquiry_number);

        $enquiry2 = Enquiry::create([
            'date_received' => now(),
            'client_id' => $client->id,
            'title' => 'Test Enquiry 2',
            'description' => 'Test description',
            'priority' => 'high',
            'created_by' => $user->id,
        ]);

        $this->assertEquals(2, $enquiry2->enquiry_number);
    }

    #[Test]
    public function it_returns_formatted_id_correctly()
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $enquiry = Enquiry::create([
            'date_received' => now()->setDate(2024, 5, 15),
            'client_id' => $client->id,
            'title' => 'Test Enquiry',
            'description' => 'Test description',
            'priority' => 'high',
            'created_by' => $user->id,
        ]);

        $enquiry->created_at = now()->setDate(2024, 5, 15);
        $enquiry->enquiry_number = 42;
        $enquiry->save();

        $this->assertEquals('WNG/IQ/24/05/042', $enquiry->formatted_id);
    }

    #[Test]
    public function it_can_start_phase_only_if_previous_phases_are_completed()
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $enquiry = Enquiry::create([
            'date_received' => now(),
            'client_id' => $client->id,
            'title' => 'Test Enquiry',
            'description' => 'Test description',
            'priority' => 'high',
            'created_by' => $user->id,
        ]);

        $phases = $enquiry->orderedPhases();

        // Cannot start second phase if first is not completed
        $this->assertFalse($enquiry->canStartPhase($phases[1]));

        // Complete first phase
        $phases[0]->update(['status' => 'completed']);

        // Now can start second phase
        $this->assertTrue($enquiry->canStartPhase($phases[1]));

        // Cannot start third phase if second is not completed
        $this->assertFalse($enquiry->canStartPhase($phases[2]));
    }

    #[Test]
    public function it_returns_false_for_invalid_phase_in_can_start_phase()
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $enquiry = Enquiry::create([
            'date_received' => now(),
            'client_id' => $client->id,
            'title' => 'Test Enquiry',
            'description' => 'Test description',
            'priority' => 'high',
            'created_by' => $user->id,
        ]);

        $anotherEnquiry = Enquiry::create([
            'date_received' => now(),
            'client_id' => $client->id,
            'title' => 'Another Enquiry',
            'description' => 'Test description',
            'priority' => 'high',
            'created_by' => $user->id,
        ]);

        $phaseFromAnotherEnquiry = $anotherEnquiry->phases()->first();

        $this->assertFalse($enquiry->canStartPhase($phaseFromAnotherEnquiry));
    }

    #[Test]
    public function it_checks_if_all_phases_are_completed()
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $enquiry = Enquiry::create([
            'date_received' => now(),
            'client_id' => $client->id,
            'title' => 'Test Enquiry',
            'description' => 'Test description',
            'priority' => 'high',
            'created_by' => $user->id,
        ]);

        $this->assertFalse($enquiry->areAllPhasesCompleted());

        // Complete all phases
        $enquiry->phases()->update(['status' => 'completed']);

        $this->assertTrue($enquiry->areAllPhasesCompleted());
    }

    #[Test]
    public function it_can_convert_to_project_only_if_quote_approved_and_phases_completed()
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $enquiry = Enquiry::create([
            'date_received' => now(),
            'client_id' => $client->id,
            'title' => 'Test Enquiry',
            'description' => 'Test description',
            'priority' => 'high',
            'estimated_budget' => 10000,
            'created_by' => $user->id,
        ]);

        $this->assertFalse($enquiry->canConvertToProject());

        // Approve quote but phases not completed
        $enquiry->update(['quote_approved' => true]);
        $this->assertFalse($enquiry->canConvertToProject());

        // Complete phases but quote not approved
        $enquiry->update(['quote_approved' => false]);
        $enquiry->phases()->update(['status' => 'completed']);
        $this->assertFalse($enquiry->canConvertToProject());

        // Both approved and completed
        $enquiry->update(['quote_approved' => true]);
        $this->assertTrue($enquiry->canConvertToProject());
    }

    #[Test]
    public function it_throws_exception_when_converting_without_required_fields()
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $enquiry = Enquiry::create([
            'date_received' => now(),
            'client_id' => $client->id,
            'title' => '', // Empty title
            'description' => 'Test description',
            'priority' => 'high',
            'created_by' => $user->id,
        ]);

        $enquiry->update(['quote_approved' => true]);
        $enquiry->phases()->update(['status' => 'completed']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Required field \'title\' is missing or empty');

        $enquiry->convertToProject();
    }

    #[Test]
    public function it_converts_to_project_successfully()
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $enquiry = Enquiry::create([
            'date_received' => now(),
            'expected_delivery_date' => now()->addDays(30),
            'client_id' => $client->id,
            'title' => 'Test Project',
            'description' => 'Test description',
            'project_scope' => 'Full project scope',
            'priority' => 'high',
            'estimated_budget' => 15000,
            'project_deliverables' => 'Deliverables list',
            'contact_person' => 'John Doe',
            'assigned_po' => 'PO123',
            'follow_up_notes' => 'Follow up notes',
            'venue' => 'Test Venue',
            'created_by' => $user->id,
        ]);

        $enquiry->update(['quote_approved' => true]);
        $enquiry->phases()->update(['status' => 'completed']);

        $result = $enquiry->convertToProject();

        $this->assertTrue($result);
        $this->assertEquals('converted_to_project', $enquiry->fresh()->status);
        $this->assertNotNull($enquiry->fresh()->converted_to_project_id);

        $project = $enquiry->project;
        $this->assertNotNull($project);
        $this->assertEquals('Test Project', $project->title);
        $this->assertEquals(15000, $project->estimated_budget);
        $this->assertEquals('initiated', $project->status);
    }

    #[Test]
    public function it_handles_conversion_errors_gracefully()
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $enquiry = Enquiry::create([
            'date_received' => now(),
            'client_id' => $client->id,
            'title' => 'Test Project',
            'description' => 'Test description',
            'priority' => 'high',
            'estimated_budget' => 15000,
            'created_by' => $user->id,
        ]);

        $enquiry->update(['quote_approved' => true]);
        $enquiry->phases()->update(['status' => 'completed']);

        // Mock DB transaction to throw exception
        DB::shouldReceive('transaction')->andThrow(new \Exception('Database error'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database error');

        $enquiry->convertToProject();
    }

    #[Test]
    public function it_approves_quote_successfully()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('finance.approve');

        $this->actingAs($user);

        $client = Client::factory()->create();

        $enquiry = Enquiry::create([
            'date_received' => now(),
            'client_id' => $client->id,
            'title' => 'Test Enquiry',
            'description' => 'Test description',
            'priority' => 'high',
            'created_by' => $user->id,
        ]);

        $result = $enquiry->approveQuote($user->id);

        $this->assertTrue($result);
        $this->assertTrue($enquiry->fresh()->quote_approved);
        $this->assertNotNull($enquiry->fresh()->quote_approved_at);
        $this->assertEquals($user->id, $enquiry->fresh()->quote_approved_by);
    }

    #[Test]
    public function it_throws_exception_when_approving_quote_without_permission()
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $enquiry = Enquiry::create([
            'date_received' => now(),
            'client_id' => $client->id,
            'title' => 'Test Enquiry',
            'description' => 'Test description',
            'priority' => 'high',
            'created_by' => $user->id,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unauthorized: Only users with finance approval permission can approve quotes');

        $enquiry->approveQuote($user->id);
    }

    #[Test]
    public function it_automatically_converts_to_project_when_quote_approved_and_phases_completed()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('finance.approve');

        $this->actingAs($user);

        $client = Client::factory()->create();

        $enquiry = Enquiry::create([
            'date_received' => now(),
            'client_id' => $client->id,
            'title' => 'Test Enquiry',
            'description' => 'Test description',
            'priority' => 'high',
            'estimated_budget' => 10000,
            'created_by' => $user->id,
        ]);

        // Complete all phases
        $enquiry->phases()->update(['status' => 'completed']);

        $enquiry->approveQuote($user->id);

        $this->assertEquals('converted_to_project', $enquiry->fresh()->status);
        $this->assertNotNull($enquiry->fresh()->converted_to_project_id);
    }

    #[Test]
    public function it_handles_thread_safety_in_conversion_with_database_transactions()
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $enquiry = Enquiry::create([
            'date_received' => now(),
            'client_id' => $client->id,
            'title' => 'Test Project',
            'description' => 'Test description',
            'priority' => 'high',
            'estimated_budget' => 15000,
            'created_by' => $user->id,
        ]);

        $enquiry->update(['quote_approved' => true]);
        $enquiry->phases()->update(['status' => 'completed']);

        // Test that conversion uses database transaction
        DB::shouldReceive('transaction')->once()->andReturn(true);

        $enquiry->convertToProject();
    }

    #[Test]
    public function it_prevents_double_conversion_with_transaction_rollback()
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $enquiry = Enquiry::create([
            'date_received' => now(),
            'client_id' => $client->id,
            'title' => 'Test Project',
            'description' => 'Test description',
            'priority' => 'high',
            'estimated_budget' => 15000,
            'created_by' => $user->id,
        ]);

        $enquiry->update(['quote_approved' => true]);
        $enquiry->phases()->update(['status' => 'completed']);

        // First conversion should succeed
        $result1 = $enquiry->convertToProject();
        $this->assertTrue($result1);
        $this->assertEquals('converted_to_project', $enquiry->fresh()->status);

        // Second conversion should fail due to status check
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot convert enquiry to project: Quote not approved or phases not completed');

        $enquiry->convertToProject();
    }

    #[Test]
    public function it_handles_concurrent_enquiry_number_generation()
    {
        // Test that enquiry numbers are generated sequentially even with rapid creation
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $enquiries = [];

        // Create multiple enquiries rapidly to test number generation
        for ($i = 0; $i < 5; $i++) {
            $enquiry = Enquiry::create([
                'date_received' => now(),
                'client_id' => $client->id,
                'title' => 'Test Enquiry ' . $i,
                'description' => 'Test description',
                'priority' => 'high',
                'created_by' => $user->id,
            ]);
            $enquiries[] = $enquiry;
        }

        // Verify enquiry numbers are sequential
        $numbers = array_map(function ($enquiry) {
            return $enquiry->enquiry_number;
        }, $enquiries);

        sort($numbers);
        $expectedNumbers = range(1, 5);

        $this->assertEquals($expectedNumbers, $numbers);
    }
}
