<?php

namespace Tests\Feature;

use App\Models\BudgetAddition;
use App\Models\TaskBudgetData;
use App\Models\User;
use Database\Factories\EnquiryTaskFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetAdditionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(); // Run seeders to set up test data
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // Create a budget task directly for testing
        $this->budgetTask = \App\Modules\Projects\Models\EnquiryTask::create([
            'project_enquiry_id' => 1, // Keep as 1 since seeders create data
            'department_id' => 1, // Keep as 1 since seeders create data
            'title' => 'Test Budget Task',
            'task_description' => 'Test budget task description',
            'status' => 'pending',
            'assigned_user_id' => $this->user->id,
            'priority' => 'medium',
            'estimated_hours' => 10,
            'due_date' => now()->addDays(7),
            'task_order' => 1,
            'created_by' => $this->user->id,
            'type' => 'budget',
        ]);

        // Create budget data for the task
        $this->budgetData = TaskBudgetData::create([
            'enquiry_task_id' => $this->budgetTask->id,
            'project_info' => [
                'projectId' => 'TEST-001',
                'enquiryTitle' => 'Test Project',
                'clientName' => 'Test Client',
                'eventVenue' => 'Test Venue',
                'setupDate' => '2025-01-01',
            ],
            'materials' => [],
            'labour' => [],
            'expenses' => [],
            'logistics' => [],
            'budget_summary' => [
                'materialsTotal' => 0,
                'labourTotal' => 0,
                'expensesTotal' => 0,
                'logisticsTotal' => 0,
                'grandTotal' => 0,
            ],
            'status' => 'draft'
        ]);
    }

    /** @test */
    public function it_auto_approves_main_budget_additions_upon_creation()
    {
        $additionData = [
            'title' => 'Test Main Budget Addition',
            'description' => 'This should be auto-approved',
            'budget_type' => 'main',
            'materials' => [
                [
                    'description' => 'Test Material',
                    'quantity' => 2,
                    'unit_price' => 100,
                    'total_price' => 200
                ]
            ],
            'labour' => [],
            'expenses' => [],
            'logistics' => []
        ];

        $response = $this->postJson("/api/projects/tasks/{$this->budgetTask->id}/budget/additions", $additionData);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'data',
                    'message'
                ])
                ->assertJsonFragment([
                    'message' => 'Budget addition created successfully'
                ]);

        // Verify the addition was created and auto-approved
        $this->assertDatabaseHas('budget_additions', [
            'title' => 'Test Main Budget Addition',
            'status' => 'approved', // Should be auto-approved for main budget additions
            'budget_type' => 'main',
            'total_amount' => 200.00
        ]);

        // Get the created addition and verify its status
        $addition = BudgetAddition::where('title', 'Test Main Budget Addition')->first();
        $this->assertEquals('approved', $addition->status);
        $this->assertNotNull($addition->approved_at);
        $this->assertEquals($this->user->id, $addition->approved_by);
        $this->assertEquals(200.00, $addition->total_amount);
    }

    /** @test */
    public function it_does_not_auto_approve_supplementary_budget_additions()
    {
        $additionData = [
            'title' => 'Test Supplementary Budget Addition',
            'description' => 'This should NOT be auto-approved',
            'budget_type' => 'supplementary',
            'materials' => [
                [
                    'description' => 'Test Material',
                    'quantity' => 1,
                    'unit_price' => 50,
                    'total_price' => 50
                ]
            ],
            'labour' => [],
            'expenses' => [],
            'logistics' => []
        ];

        $response = $this->postJson("/api/projects/tasks/{$this->budgetTask->id}/budget/additions", $additionData);

        $response->assertStatus(201);

        // Verify the addition was created but NOT auto-approved
        $this->assertDatabaseHas('budget_additions', [
            'title' => 'Test Supplementary Budget Addition',
            'status' => 'draft', // Should remain draft for supplementary additions
            'budget_type' => 'supplementary',
            'total_amount' => 50.00
        ]);

        // Get the created addition and verify its status
        $addition = BudgetAddition::where('title', 'Test Supplementary Budget Addition')->first();
        $this->assertEquals('draft', $addition->status);
        $this->assertNull($addition->approved_at);
        $this->assertNull($addition->approved_by);
        $this->assertEquals(50.00, $addition->total_amount);
    }

    /** @test */
    public function it_retrieves_budget_additions_for_a_task()
    {

        // Create a main budget addition (should be auto-approved)
        $mainAddition = BudgetAddition::create([
            'task_budget_data_id' => $this->budgetData->id,
            'title' => 'Main Addition',
            'description' => 'Auto-approved main addition',
            'budget_type' => 'main',
            'materials' => [['description' => 'Material', 'quantity' => 1, 'unit_price' => 100, 'total_price' => 100]],
            'labour' => [],
            'expenses' => [],
            'logistics' => [],
            'status' => 'approved',
            'created_by' => $this->user->id,
            'approved_by' => $this->user->id,
            'approved_at' => now(),
            'total_amount' => 100.00,
        ]);

        // Create a supplementary budget addition (draft)
        $supplementaryAddition = BudgetAddition::create([
            'task_budget_data_id' => $this->budgetData->id,
            'title' => 'Supplementary Addition',
            'description' => 'Draft supplementary addition',
            'budget_type' => 'supplementary',
            'materials' => [['description' => 'Material 2', 'quantity' => 1, 'unit_price' => 50, 'total_price' => 50]],
            'labour' => [],
            'expenses' => [],
            'logistics' => [],
            'status' => 'draft',
            'created_by' => $this->user->id,
            'total_amount' => 50.00,
        ]);

        $response = $this->getJson("/api/projects/tasks/{$this->budgetTask->id}/budget/additions");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'data',
                    'message'
                ]);

        $data = $response->json('data');

        // Should have both additions
        $this->assertCount(2, $data);

        // Check that main addition is approved
        $mainAdditionData = collect($data)->firstWhere('title', 'Main Addition');
        $this->assertEquals('approved', $mainAdditionData['status']);
        $this->assertEquals('main', $mainAdditionData['budget_type']);

        // Check that supplementary addition is draft
        $supplementaryAdditionData = collect($data)->firstWhere('title', 'Supplementary Addition');
        $this->assertEquals('draft', $supplementaryAdditionData['status']);
        $this->assertEquals('supplementary', $supplementaryAdditionData['budget_type']);
    }
}
