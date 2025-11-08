<?php

namespace Tests\Feature;

use App\Models\TaskBudgetData;
use App\Models\TaskMaterialsData;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(); // Run seeders to set up test data
    }

    /** @test */
    public function it_validates_budget_data_structure_on_save()
    {
        // Create a test task
        $task = EnquiryTask::factory()->create(['type' => 'budget']);

        $invalidData = [
            'projectInfo' => [
                'projectId' => '', // Empty required field
                'enquiryTitle' => 'Test Project',
                'clientName' => 'Test Client',
                'eventVenue' => 'Test Venue',
                'setupDate' => '2025-01-01',
            ],
            'materials' => [],
            'labour' => [],
            'expenses' => [],
            'logistics' => [],
            'budgetSummary' => [
                'materialsTotal' => 0,
                'labourTotal' => 0,
                'expensesTotal' => 0,
                'logisticsTotal' => 0,
                'grandTotal' => 0,
            ],
            'status' => 'draft'
        ];

        $response = $this->postJson("/api/projects/tasks/{$task->id}/budget", $invalidData);

        $response->assertStatus(422)
                ->assertJsonStructure([
                    'message',
                    'errors',
                    'error_type'
                ])
                ->assertJsonFragment([
                    'error_type' => 'validation_error'
                ]);
    }

    /** @test */
    public function it_validates_business_logic_for_budget_calculations()
    {
        $task = EnquiryTask::factory()->create(['type' => 'budget']);

        $invalidCalculationData = [
            'projectInfo' => [
                'projectId' => 'TEST-001',
                'enquiryTitle' => 'Test Project',
                'clientName' => 'Test Client',
                'eventVenue' => 'Test Venue',
                'setupDate' => '2025-01-01',
            ],
            'materials' => [
                [
                    'id' => 'mat-1',
                    'elementType' => 'banner',
                    'name' => 'Test Banner',
                    'category' => 'production',
                    'isIncluded' => true,
                    'materials' => [
                        [
                            'id' => 'item-1',
                            'description' => 'Test Material',
                            'unitOfMeasurement' => 'pcs',
                            'quantity' => 2,
                            'isIncluded' => true,
                            'unitPrice' => 100,
                            'totalPrice' => 150, // Wrong calculation: should be 200
                        ]
                    ]
                ]
            ],
            'labour' => [],
            'expenses' => [],
            'logistics' => [],
            'budgetSummary' => [
                'materialsTotal' => 150, // Wrong total
                'labourTotal' => 0,
                'expensesTotal' => 0,
                'logisticsTotal' => 0,
                'grandTotal' => 150,
            ],
            'status' => 'draft'
        ];

        $response = $this->postJson("/api/projects/tasks/{$task->id}/budget", $invalidCalculationData);

        $response->assertStatus(422)
                ->assertJsonFragment([
                    'error_type' => 'business_logic_error'
                ]);
    }

    /** @test */
    public function it_validates_budget_status_transitions()
    {
        $task = EnquiryTask::factory()->create(['type' => 'budget']);

        // First create a budget in draft status
        $draftData = $this->getValidBudgetData();
        $this->postJson("/api/projects/tasks/{$task->id}/budget", $draftData)->assertStatus(200);

        // Try to submit directly to approved (invalid transition)
        $approvedData = $this->getValidBudgetData();
        $approvedData['status'] = 'approved';

        $response = $this->postJson("/api/projects/tasks/{$task->id}/budget", $approvedData);

        $response->assertStatus(422)
                ->assertJsonFragment([
                    'error_type' => 'business_logic_error'
                ]);
    }

    /** @test */
    public function it_saves_valid_budget_data_successfully()
    {
        $task = EnquiryTask::factory()->create(['type' => 'budget']);

        $validData = $this->getValidBudgetData();

        $response = $this->postJson("/api/projects/tasks/{$task->id}/budget", $validData);

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'data',
                    'message'
                ])
                ->assertJsonFragment([
                    'message' => 'Budget data saved successfully'
                ]);

        $this->assertDatabaseHas('task_budget_data', [
            'enquiry_task_id' => $task->id,
            'status' => 'draft'
        ]);
    }

    /** @test */
    public function it_handles_nonexistent_task_gracefully()
    {
        $nonexistentTaskId = 99999;

        $data = $this->getValidBudgetData();

        $response = $this->postJson("/api/projects/tasks/{$nonexistentTaskId}/budget", $data);

        $response->assertStatus(404)
                ->assertJsonFragment([
                    'error_type' => 'task_not_found'
                ]);
    }

    /** @test */
    public function it_validates_materials_import_requirements()
    {
        $task = EnquiryTask::factory()->create(['type' => 'budget']);

        $response = $this->postJson("/api/projects/tasks/{$task->id}/budget/import-materials");

        $response->assertStatus(404) // No materials task found
                ->assertJsonFragment([
                    'error_type' => 'materials_task_not_found'
                ]);
    }

    /** @test */
    public function it_submits_budget_for_approval_successfully()
    {
        $task = EnquiryTask::factory()->create(['type' => 'budget']);

        // Create budget data first
        $data = $this->getValidBudgetData();
        $this->postJson("/api/projects/tasks/{$task->id}/budget", $data)->assertStatus(200);

        // Submit for approval
        $response = $this->postJson("/api/projects/tasks/{$task->id}/budget/submit-approval");

        $response->assertStatus(200)
                ->assertJsonFragment([
                    'message' => 'Budget submitted for approval successfully'
                ]);

        $this->assertDatabaseHas('task_budget_data', [
            'enquiry_task_id' => $task->id,
            'status' => 'pending_approval'
        ]);
    }

    /** @test */
    public function it_retrieves_budget_data_successfully()
    {
        $task = EnquiryTask::factory()->create(['type' => 'budget']);

        // Create budget data
        $data = $this->getValidBudgetData();
        $this->postJson("/api/projects/tasks/{$task->id}/budget", $data)->assertStatus(200);

        // Retrieve budget data
        $response = $this->getJson("/api/projects/tasks/{$task->id}/budget");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'data',
                    'message'
                ])
                ->assertJsonFragment([
                    'message' => 'Budget data retrieved successfully'
                ]);
    }

    /** @test */
    public function it_returns_default_structure_for_new_budget()
    {
        $task = EnquiryTask::factory()->create(['type' => 'budget']);

        $response = $this->getJson("/api/projects/tasks/{$task->id}/budget");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        'projectInfo',
                        'materials',
                        'labour',
                        'expenses',
                        'logistics',
                        'budgetSummary',
                        'status'
                    ]
                ]);
    }

    /**
     * Helper method to generate valid budget data for testing
     */
    private function getValidBudgetData(): array
    {
        return [
            'projectInfo' => [
                'projectId' => 'TEST-001',
                'enquiryTitle' => 'Test Project',
                'clientName' => 'Test Client',
                'eventVenue' => 'Test Venue',
                'setupDate' => '2025-01-01',
                'setDownDate' => '2025-01-02',
            ],
            'materials' => [
                [
                    'id' => 'mat-1',
                    'elementType' => 'banner',
                    'name' => 'Test Banner',
                    'category' => 'production',
                    'isIncluded' => true,
                    'materials' => [
                        [
                            'id' => 'item-1',
                            'description' => 'Test Material',
                            'unitOfMeasurement' => 'pcs',
                            'quantity' => 2,
                            'isIncluded' => true,
                            'unitPrice' => 100,
                            'totalPrice' => 200,
                        ]
                    ]
                ]
            ],
            'labour' => [
                [
                    'id' => 'lab-1',
                    'category' => 'Production',
                    'type' => 'Carpenter',
                    'description' => 'Test labour',
                    'unit' => 'PAX',
                    'quantity' => 1,
                    'unitRate' => 500,
                    'amount' => 500,
                ]
            ],
            'expenses' => [
                [
                    'id' => 'exp-1',
                    'description' => 'Test Expense',
                    'category' => 'Miscellaneous',
                    'amount' => 1000,
                ]
            ],
            'logistics' => [
                [
                    'id' => 'log-1',
                    'vehicleReg' => 'KAA 123A',
                    'description' => 'Test Logistics',
                    'category' => 'transport',
                    'unit' => 'trip',
                    'quantity' => 1,
                    'unitRate' => 2000,
                    'amount' => 2000,
                ]
            ],
            'budgetSummary' => [
                'materialsTotal' => 200,
                'labourTotal' => 500,
                'expensesTotal' => 1000,
                'logisticsTotal' => 2000,
                'grandTotal' => 3700,
            ],
            'status' => 'draft'
        ];
    }
}
