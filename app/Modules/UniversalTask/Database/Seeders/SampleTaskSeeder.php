<?php

namespace App\Modules\UniversalTask\Database\Seeders;

use App\Models\User;
use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskAssignment;
use App\Modules\UniversalTask\Models\TaskComment;
use App\Modules\UniversalTask\Models\TaskIssue;
use App\Modules\UniversalTask\Models\TaskTimeEntry;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class SampleTaskSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get existing users or create sample ones
        $users = User::all();
        if ($users->isEmpty()) {
            // Create sample users if none exist
            $users = collect([
                User::create([
                    'name' => 'John Doe',
                    'email' => 'john.doe@example.com',
                    'password' => bcrypt('password'),
                    'department_id' => 1
                ]),
                User::create([
                    'name' => 'Jane Smith',
                    'email' => 'jane.smith@example.com',
                    'password' => bcrypt('password'),
                    'department_id' => 2
                ]),
                User::create([
                    'name' => 'Bob Johnson',
                    'email' => 'bob.johnson@example.com',
                    'password' => bcrypt('password'),
                    'department_id' => 1
                ])
            ]);
        }

        $sampleTasks = [
            [
                'title' => 'Implement User Authentication System',
                'description' => 'Develop a comprehensive user authentication system with login, registration, password reset, and role-based access control.',
                'task_type' => 'feature',
                'status' => 'in_progress',
                'priority' => 'high',
                'department_id' => 1, // Engineering
                'assigned_user_id' => $users->first()->id,
                'created_by' => $users->first()->id,
                'estimated_hours' => 40,
                'due_date' => Carbon::now()->addDays(14),
                'tags' => ['authentication', 'security', 'backend']
            ],
            [
                'title' => 'Design New Dashboard UI',
                'description' => 'Create modern, responsive dashboard interface with charts, metrics, and user-friendly navigation.',
                'task_type' => 'feature',
                'status' => 'pending',
                'priority' => 'medium',
                'department_id' => 2, // Design
                'assigned_user_id' => $users->skip(1)->first()->id,
                'created_by' => $users->skip(1)->first()->id,
                'estimated_hours' => 24,
                'due_date' => Carbon::now()->addDays(10),
                'tags' => ['ui', 'dashboard', 'frontend']
            ],
            [
                'title' => 'Fix Mobile Responsiveness Issues',
                'description' => 'Address mobile responsiveness problems reported in the latest user feedback survey.',
                'task_type' => 'bug',
                'status' => 'completed',
                'priority' => 'urgent',
                'department_id' => 1, // Engineering
                'assigned_user_id' => $users->first()->id,
                'created_by' => $users->first()->id,
                'estimated_hours' => 8,
                'actual_hours' => 6,
                'due_date' => Carbon::now()->addDays(3),
                'started_at' => Carbon::now()->subDays(2),
                'completed_at' => Carbon::now()->subDay(),
                'tags' => ['mobile', 'responsive', 'bug']
            ],
            [
                'title' => 'Prepare Q4 Marketing Campaign',
                'description' => 'Plan and prepare content for the Q4 marketing campaign including social media posts, email newsletters, and promotional materials.',
                'task_type' => 'marketing',
                'status' => 'in_progress',
                'priority' => 'medium',
                'department_id' => 3, // Marketing
                'assigned_user_id' => $users->last()->id,
                'created_by' => $users->last()->id,
                'estimated_hours' => 32,
                'due_date' => Carbon::now()->addDays(21),
                'tags' => ['marketing', 'campaign', 'q4']
            ],
            [
                'title' => 'Database Performance Optimization',
                'description' => 'Optimize database queries and indexes to improve application performance and reduce response times.',
                'task_type' => 'maintenance',
                'status' => 'pending',
                'priority' => 'high',
                'department_id' => 1, // Engineering
                'assigned_user_id' => $users->first()->id,
                'created_by' => $users->first()->id,
                'estimated_hours' => 16,
                'due_date' => Carbon::now()->addDays(7),
                'tags' => ['database', 'performance', 'optimization']
            ],
            [
                'title' => 'User Training Documentation',
                'description' => 'Create comprehensive user training documentation and video tutorials for the new features.',
                'task_type' => 'documentation',
                'status' => 'pending',
                'priority' => 'low',
                'department_id' => 2, // Design
                'assigned_user_id' => $users->skip(1)->first()->id,
                'created_by' => $users->skip(1)->first()->id,
                'estimated_hours' => 20,
                'due_date' => Carbon::now()->addDays(30),
                'tags' => ['documentation', 'training', 'user-guide']
            ],
            [
                'title' => 'Security Audit and Penetration Testing',
                'description' => 'Conduct comprehensive security audit and penetration testing for the application.',
                'task_type' => 'security',
                'status' => 'blocked',
                'priority' => 'urgent',
                'department_id' => 1, // Engineering
                'assigned_user_id' => $users->first()->id,
                'created_by' => $users->first()->id,
                'estimated_hours' => 24,
                'blocked_reason' => 'Waiting for external security consultant availability',
                'due_date' => Carbon::now()->addDays(5),
                'tags' => ['security', 'audit', 'penetration-testing']
            ],
            [
                'title' => 'Client Feedback Integration',
                'description' => 'Review and integrate client feedback from the recent user survey into the product roadmap.',
                'task_type' => 'analysis',
                'status' => 'review',
                'priority' => 'medium',
                'department_id' => 3, // Marketing
                'assigned_user_id' => $users->last()->id,
                'created_by' => $users->last()->id,
                'estimated_hours' => 12,
                'due_date' => Carbon::now()->addDays(12),
                'tags' => ['feedback', 'client', 'roadmap']
            ]
        ];

        $createdTasks = [];
        foreach ($sampleTasks as $taskData) {
            $task = Task::create($taskData);
            $createdTasks[] = $task;

            // Create task assignments for some tasks
            if (rand(0, 1)) { // 50% chance
                TaskAssignment::create([
                    'task_id' => $task->id,
                    'user_id' => $task->assigned_user_id,
                    'assigned_by' => $task->created_by,
                    'assigned_at' => $task->created_at,
                    'role' => 'assignee',
                    'is_primary' => true
                ]);
            }
        }

        // Create subtasks for the first task
        $parentTask = $createdTasks[0];
        $subtasks = [
            [
                'title' => 'Implement Login API',
                'description' => 'Create REST API endpoint for user login with JWT authentication.',
                'status' => 'completed',
                'priority' => 'high',
                'estimated_hours' => 8,
                'actual_hours' => 6
            ],
            [
                'title' => 'Implement Registration API',
                'description' => 'Create user registration endpoint with email verification.',
                'status' => 'completed',
                'priority' => 'high',
                'estimated_hours' => 6,
                'actual_hours' => 7
            ],
            [
                'title' => 'Frontend Login Form',
                'description' => 'Create responsive login form with validation.',
                'status' => 'in_progress',
                'priority' => 'high',
                'estimated_hours' => 4
            ],
            [
                'title' => 'Password Reset Functionality',
                'description' => 'Implement secure password reset flow with email notifications.',
                'status' => 'pending',
                'priority' => 'medium',
                'estimated_hours' => 6
            ]
        ];

        foreach ($subtasks as $subtaskData) {
            Task::create(array_merge($subtaskData, [
                'parent_task_id' => $parentTask->id,
                'task_type' => 'development',
                'department_id' => $parentTask->department_id,
                'assigned_user_id' => $parentTask->assigned_user_id,
                'created_by' => $parentTask->created_by,
                'due_date' => $parentTask->due_date,
                'tags' => ['subtask', 'authentication']
            ]));
        }

        // Create sample comments
        $sampleComments = [
            [
                'task_id' => $createdTasks[0]->id,
                'user_id' => $users->first()->id,
                'content' => 'Started working on the authentication system. The JWT implementation is going well.'
            ],
            [
                'task_id' => $createdTasks[0]->id,
                'user_id' => $users->skip(1)->first()->id,
                'content' => 'Great progress! Can you also add OAuth support for social login?'
            ],
            [
                'task_id' => $createdTasks[2]->id,
                'user_id' => $users->first()->id,
                'content' => 'Fixed the mobile responsiveness issues. Tested on iOS Safari, Chrome Mobile, and Android Chrome.'
            ]
        ];

        foreach ($sampleComments as $commentData) {
            TaskComment::create($commentData);
        }

        // Create sample issues
        $sampleIssues = [
            [
                'task_id' => $createdTasks[0]->id,
                'title' => 'JWT Token Expiration Issue',
                'description' => 'Users are being logged out unexpectedly due to token expiration timing.',
                'issue_type' => 'bug',
                'severity' => 'medium',
                'status' => 'open',
                'reported_by' => $users->first()->id,
                'assigned_to' => $users->first()->id,
                'reported_at' => Carbon::now()->subDays(2)
            ],
            [
                'task_id' => $createdTasks[6]->id,
                'title' => 'Security Consultant Delay',
                'description' => 'The external security consultant is delayed and we need to adjust our timeline.',
                'issue_type' => 'bug',
                'severity' => 'high',
                'status' => 'open',
                'reported_by' => $users->first()->id,
                'reported_at' => Carbon::now()->subDay()
            ]
        ];

        foreach ($sampleIssues as $issueData) {
            TaskIssue::create($issueData);
        }

        // Create sample time entries
        $sampleTimeEntries = [
            [
                'task_id' => $createdTasks[0]->id,
                'user_id' => $users->first()->id,
                'hours' => 4.5,
                'date_worked' => Carbon::now()->subDays(1),
                'description' => 'Implemented JWT authentication middleware',
                'is_billable' => true
            ],
            [
                'task_id' => $createdTasks[0]->id,
                'user_id' => $users->first()->id,
                'hours' => 3.0,
                'date_worked' => Carbon::now()->subDays(2),
                'description' => 'Created user registration API endpoint',
                'is_billable' => true
            ],
            [
                'task_id' => $createdTasks[2]->id,
                'user_id' => $users->first()->id,
                'hours' => 6.0,
                'date_worked' => Carbon::now()->subDay(),
                'description' => 'Fixed mobile CSS issues and tested across devices',
                'is_billable' => true
            ]
        ];

        foreach ($sampleTimeEntries as $timeEntryData) {
            TaskTimeEntry::create($timeEntryData);
        }

        $this->command->info('Sample tasks, subtasks, comments, issues, and time entries created successfully!');
    }
}