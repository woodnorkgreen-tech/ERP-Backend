<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskAssignment;
use App\Modules\UniversalTask\Models\TaskComment;
use App\Modules\UniversalTask\Models\TaskDependency;
use App\Modules\UniversalTask\Models\TaskIssue;
use App\Modules\UniversalTask\Models\TaskExperienceLog;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class UniversalTaskSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding Universal Task System data...');

        // Get departments and users
        $departments = Department::all();
        $users = User::all();

        if ($departments->isEmpty() || $users->isEmpty()) {
            $this->command->error('Departments and users must be seeded first!');
            return;
        }

        // Sample task data
        $taskTemplates = [
            // Projects Department Tasks
            [
                'title' => 'Q4 Project Planning & Budget Review',
                'description' => 'Review and finalize project budgets for Q4 2024. Coordinate with finance team to ensure all projects are properly budgeted.',
                'status' => 'in_progress',
                'priority' => 'high',
                'department' => 'Projects',
                'estimated_hours' => 16,
                'due_date' => Carbon::now()->addDays(7),
                'tags' => ['planning', 'budget', 'q4'],
                'subtasks' => [
                    ['title' => 'Gather project data from all departments', 'status' => 'completed', 'estimated_hours' => 4],
                    ['title' => 'Review budget allocations', 'status' => 'in_progress', 'estimated_hours' => 6],
                    ['title' => 'Coordinate with Finance team', 'status' => 'pending', 'estimated_hours' => 3],
                    ['title' => 'Finalize Q4 project roadmap', 'status' => 'pending', 'estimated_hours' => 3],
                ]
            ],
            [
                'title' => 'Client Onboarding Process Optimization',
                'description' => 'Streamline the client onboarding workflow to reduce processing time from 2 weeks to 1 week.',
                'status' => 'pending',
                'priority' => 'medium',
                'department' => 'Client Service',
                'estimated_hours' => 20,
                'due_date' => Carbon::now()->addDays(14),
                'tags' => ['process', 'optimization', 'client-onboarding'],
            ],
            [
                'title' => 'Design Asset Library Organization',
                'description' => 'Reorganize and catalog all design assets in the shared drive. Create proper folder structure and naming conventions.',
                'status' => 'in_progress',
                'priority' => 'medium',
                'department' => 'Design/Creatives',
                'estimated_hours' => 12,
                'due_date' => Carbon::now()->addDays(10),
                'tags' => ['organization', 'assets', 'design'],
            ],
            [
                'title' => 'Monthly Financial Report Generation',
                'description' => 'Generate comprehensive financial reports for November 2024 including P&L, balance sheet, and cash flow statements.',
                'status' => 'completed',
                'priority' => 'high',
                'department' => 'Accounts/Finance',
                'estimated_hours' => 8,
                'due_date' => Carbon::now()->subDays(2),
                'tags' => ['reporting', 'finance', 'monthly'],
            ],
            [
                'title' => 'Supplier Performance Evaluation',
                'description' => 'Evaluate supplier performance for Q3 2024. Review delivery times, quality metrics, and pricing competitiveness.',
                'status' => 'review',
                'priority' => 'medium',
                'department' => 'Procurement',
                'estimated_hours' => 10,
                'due_date' => Carbon::now()->addDays(5),
                'tags' => ['suppliers', 'evaluation', 'q3'],
            ],
            [
                'title' => 'Production Line Efficiency Analysis',
                    'description' => 'Analyze production line bottlenecks and identify opportunities for efficiency improvements.',
                    'status' => 'blocked',
                    'priority' => 'critical',
                'department' => 'Production',
                'estimated_hours' => 15,
                'due_date' => Carbon::now()->addDays(3),
                'tags' => ['efficiency', 'analysis', 'production'],
                'blocked_reason' => 'Waiting for production data access'
            ],
            [
                'title' => 'Logistics Route Optimization',
                'description' => 'Optimize delivery routes to reduce transportation costs by 15% and improve delivery times.',
                'status' => 'pending',
                'priority' => 'low',
                'department' => 'Logistics',
                'estimated_hours' => 25,
                'due_date' => Carbon::now()->addDays(21),
                'tags' => ['optimization', 'routes', 'cost-reduction'],
            ],
            [
                'title' => 'Inventory Management System Audit',
                'description' => 'Conduct comprehensive audit of inventory management system and identify areas for improvement.',
                'status' => 'in_progress',
                'priority' => 'medium',
                'department' => 'Stores',
                'estimated_hours' => 18,
                'due_date' => Carbon::now()->addDays(12),
                'tags' => ['audit', 'inventory', 'system'],
            ],
            [
                'title' => 'Cost Reduction Initiative Q4',
                'description' => 'Identify and implement cost reduction measures across all departments to meet Q4 targets.',
                'status' => 'pending',
                'priority' => 'high',
                'department' => 'Costing',
                'estimated_hours' => 30,
                'due_date' => Carbon::now()->addDays(30),
                'tags' => ['cost-reduction', 'initiative', 'q4'],
            ],
            [
                'title' => 'Team Performance Dashboard Development',
                'description' => 'Create comprehensive dashboard to track team performance metrics and KPIs across all departments.',
                'status' => 'in_progress',
                'priority' => 'medium',
                'department' => 'Teams',
                'estimated_hours' => 22,
                'due_date' => Carbon::now()->addDays(16),
                'tags' => ['dashboard', 'performance', 'kpi'],
            ],
            // Overdue tasks
            [
                'title' => 'Website Security Audit',
                'description' => 'Conduct security audit of company website and implement recommended fixes.',
                'status' => 'overdue',
                'priority' => 'critical',
                'department' => 'Projects',
                'estimated_hours' => 14,
                'due_date' => Carbon::now()->subDays(5),
                'tags' => ['security', 'audit', 'website'],
            ],
            [
                'title' => 'Employee Training Program Setup',
                'description' => 'Set up comprehensive training program for new employees and skill development.',
                'status' => 'pending',
                'priority' => 'low',
                'department' => 'Teams',
                'estimated_hours' => 35,
                'due_date' => Carbon::now()->addDays(45),
                'tags' => ['training', 'development', 'hr'],
            ],
        ];

        $createdTasks = [];

        foreach ($taskTemplates as $taskData) {
            $department = $departments->where('name', $taskData['department'])->first();
            if (!$department) continue;

            // Get a random user from the department or any user
            $departmentUsers = $users->where('department_id', $department->id);
            $assignee = $departmentUsers->isNotEmpty()
                ? $departmentUsers->random()
                : $users->random();

            $creator = $users->random();

            // Create main task
            $task = Task::create([
                'title' => $taskData['title'],
                'description' => $taskData['description'],
                'status' => $taskData['status'],
                'priority' => $taskData['priority'],
                'task_type' => 'general',
                'department_id' => $department->id,
                'assigned_user_id' => $assignee->id,
                'created_by' => $creator->id,
                'estimated_hours' => $taskData['estimated_hours'],
                'due_date' => $taskData['due_date'],
                'tags' => $taskData['tags'],
                'blocked_reason' => $taskData['blocked_reason'] ?? null,
                'started_at' => in_array($taskData['status'], ['in_progress', 'review', 'completed'])
                    ? Carbon::now()->subDays(rand(1, 7))
                    : null,
                'completed_at' => $taskData['status'] === 'completed'
                    ? Carbon::now()->subDays(rand(1, 3))
                    : null,
            ]);

            // Create task assignment record
            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $assignee->id,
                'assigned_by' => $creator->id,
                'assigned_at' => now(),
                'role' => 'assignee',
                'is_primary' => true,
            ]);

            $createdTasks[] = $task;

            // Create subtasks if defined
            if (isset($taskData['subtasks'])) {
                foreach ($taskData['subtasks'] as $index => $subtaskData) {
                    $subtask = Task::create([
                        'title' => $subtaskData['title'],
                        'description' => '',
                        'status' => $subtaskData['status'],
                        'priority' => 'medium',
                        'task_type' => 'subtask',
                        'parent_task_id' => $task->id,
                        'department_id' => $department->id,
                        'assigned_user_id' => $assignee->id,
                        'created_by' => $creator->id,
                        'estimated_hours' => $subtaskData['estimated_hours'],
                        'due_date' => $taskData['due_date'],
                        'started_at' => $subtaskData['status'] === 'completed'
                            ? Carbon::now()->subDays(rand(1, 5))
                            : null,
                        'completed_at' => $subtaskData['status'] === 'completed'
                            ? Carbon::now()->subDays(rand(1, 3))
                            : null,
                    ]);

                    TaskAssignment::create([
                        'task_id' => $subtask->id,
                        'user_id' => $assignee->id,
                        'assigned_by' => $creator->id,
                        'assigned_at' => now(),
                        'role' => 'assignee',
                        'is_primary' => true,
                    ]);
                }
            }

            // Add some comments to tasks
            if (rand(1, 3) > 1) { // 66% chance of having comments
                $commentUsers = $users->random(min(3, $users->count()));
                foreach ($commentUsers as $commentUser) {
                    TaskComment::create([
                        'task_id' => $task->id,
                        'user_id' => $commentUser->id,
                        'content' => $this->getRandomComment(),
                    ]);
                }
            }

            // Add some issues to tasks (especially blocked/overdue ones)
            if (in_array($taskData['status'], ['blocked', 'overdue']) || rand(1, 4) === 1) {
                TaskIssue::create([
                    'task_id' => $task->id,
                    'title' => $this->getRandomIssueTitle(),
                    'description' => $this->getRandomIssueDescription(),
                    'issue_type' => ['blocker', 'technical', 'resource', 'dependency', 'general'][rand(0, 4)],
                    'severity' => ['low', 'medium', 'high', 'critical'][rand(0, 3)],
                    'status' => ['open', 'in_progress', 'resolved'][rand(0, 2)],
                    'reported_by' => $assignee->id,
                    'assigned_to' => rand(0, 1) ? $users->random()->id : null,
                    'reported_at' => Carbon::now()->subDays(rand(1, 7)),
                ]);
            }

            // Add experience logs to completed tasks
            if ($taskData['status'] === 'completed' && rand(1, 2) === 1) {
                TaskExperienceLog::create([
                    'task_id' => $task->id,
                    'user_id' => $assignee->id,
                    'title' => 'Task Completion Notes',
                    'content' => $this->getRandomExperienceLog(),
                    'log_type' => 'completion_notes',
                    'is_public' => rand(0, 1),
                    'logged_at' => Carbon::now()->subDays(rand(1, 3)),
                ]);
            }
        }

        // Create some task dependencies
        if (count($createdTasks) >= 3) {
            $dependencyPairs = [
                [0, 1], // First task blocks second
                [2, 3], // Third task blocks fourth
                [1, 4], // Second task blocks fifth
            ];

            foreach ($dependencyPairs as $pair) {
                if (isset($createdTasks[$pair[0]]) && isset($createdTasks[$pair[1]])) {
                    TaskDependency::create([
                        'task_id' => $createdTasks[$pair[1]]->id,
                        'depends_on_task_id' => $createdTasks[$pair[0]]->id,
                        'dependency_type' => 'blocks',
                    ]);
                }
            }
        }

        $this->command->info('Universal Task System seeded successfully!');
        $this->command->info('Created ' . count($createdTasks) . ' sample tasks across all departments');
        $this->command->info('Tasks include various statuses, priorities, and relationships');
    }

    private function getRandomComment(): string
    {
        $comments = [
            'Working on this task now. Should be completed by EOD.',
            'Need clarification on the requirements. Can we schedule a quick call?',
            'This is dependent on the previous task completion.',
            'Great progress so far! Just need to finalize the documentation.',
            'Encountered a small issue, but working on a solution.',
            'Task is now ready for review.',
            'Updated the specifications based on client feedback.',
            'This looks good to proceed with the next phase.',
        ];

        return $comments[array_rand($comments)];
    }

    private function getRandomIssueTitle(): string
    {
        $titles = [
            'Data validation error',
            'Performance issue with large datasets',
            'UI responsiveness on mobile devices',
            'Integration with external API failing',
            'Missing documentation for new features',
            'Security vulnerability in authentication',
            'Database connection timeout',
            'File upload size limitation',
        ];

        return $titles[array_rand($titles)];
    }

    private function getRandomIssueDescription(): string
    {
        $descriptions = [
            'When processing large amounts of data, the system becomes unresponsive and takes too long to complete operations.',
            'The mobile interface is not properly scaling to different screen sizes, causing layout issues.',
            'External API calls are failing intermittently, causing data synchronization problems.',
            'New features have been implemented but lack proper documentation for other developers.',
            'Found a potential security vulnerability in the user authentication flow that needs immediate attention.',
            'Database connections are timing out during peak usage hours, affecting system performance.',
            'Users are unable to upload files larger than the current limit, impacting workflow.',
        ];

        return $descriptions[array_rand($descriptions)];
    }

    private function getRandomExperienceLog(): string
    {
        $logs = [
            'Learned that early communication with stakeholders is crucial for project success. The client feedback helped us avoid major rework.',
            'Discovered that breaking down complex tasks into smaller, manageable subtasks significantly improves team productivity.',
            'Found that automated testing saves considerable time in the long run, even though it requires initial setup effort.',
            'Realized the importance of maintaining clear documentation throughout the development process.',
            'Learned that regular check-ins with the team help identify and resolve blockers early.',
            'Discovered that using version control properly prevents many potential issues and makes collaboration easier.',
        ];

        return $logs[array_rand($logs)];
    }
}