<?php

namespace App\Modules\UniversalTask\Database\Seeders;

use App\Modules\UniversalTask\Models\TaskTemplate;
use Illuminate\Database\Seeder;

class TaskTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Project Onboarding Workflow',
                'description' => 'Complete workflow for onboarding new projects including initial setup, stakeholder meetings, and kickoff activities.',
                'category' => 'project_management',
                'template_data' => [
                    'tasks' => [
                        [
                            'id' => 'project_setup',
                            'title' => 'Project Setup & Documentation',
                            'description' => 'Create project folder structure, documentation templates, and initial project plan.',
                            'task_type' => 'setup',
                            'priority' => 'high',
                            'estimated_hours' => 8,
                            'tags' => ['setup', 'documentation']
                        ],
                        [
                            'id' => 'stakeholder_identification',
                            'title' => 'Identify Stakeholders',
                            'description' => 'Identify and document all project stakeholders, their roles, and contact information.',
                            'task_type' => 'planning',
                            'priority' => 'high',
                            'estimated_hours' => 4,
                            'tags' => ['stakeholders', 'planning']
                        ],
                        [
                            'id' => 'kickoff_meeting',
                            'title' => 'Project Kickoff Meeting',
                            'description' => 'Schedule and conduct project kickoff meeting with all stakeholders.',
                            'task_type' => 'meeting',
                            'priority' => 'urgent',
                            'estimated_hours' => 2,
                            'due_date_offset_days' => 7,
                            'tags' => ['meeting', 'kickoff']
                        ],
                        [
                            'id' => 'requirements_gathering',
                            'title' => 'Requirements Gathering',
                            'description' => 'Collect and document detailed project requirements from all stakeholders.',
                            'task_type' => 'analysis',
                            'priority' => 'high',
                            'estimated_hours' => 16,
                            'due_date_offset_days' => 14,
                            'tags' => ['requirements', 'analysis']
                        ],
                        [
                            'id' => 'timeline_planning',
                            'title' => 'Project Timeline Planning',
                            'description' => 'Create detailed project timeline with milestones and deliverables.',
                            'task_type' => 'planning',
                            'priority' => 'high',
                            'estimated_hours' => 6,
                            'due_date_offset_days' => 10,
                            'tags' => ['planning', 'timeline']
                        ]
                    ],
                    'dependencies' => [
                        [
                            'task_id' => 'stakeholder_identification',
                            'depends_on_task_id' => 'project_setup',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'kickoff_meeting',
                            'depends_on_task_id' => 'stakeholder_identification',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'requirements_gathering',
                            'depends_on_task_id' => 'kickoff_meeting',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'timeline_planning',
                            'depends_on_task_id' => 'requirements_gathering',
                            'dependency_type' => 'blocks'
                        ]
                    ]
                ],
                'variables' => [
                    'project_name' => [
                        'required' => true,
                        'description' => 'Name of the project'
                    ],
                    'project_manager' => [
                        'required' => true,
                        'description' => 'Name of the project manager'
                    ],
                    'client_name' => [
                        'required' => false,
                        'description' => 'Name of the client (if applicable)'
                    ]
                ],
                'tags' => ['project', 'onboarding', 'workflow']
            ],
            [
                'name' => 'Bug Fix Process',
                'description' => 'Standard process for identifying, fixing, and deploying bug fixes.',
                'category' => 'development',
                'template_data' => [
                    'tasks' => [
                        [
                            'id' => 'bug_reproduction',
                            'title' => 'Reproduce Bug',
                            'description' => 'Reproduce the reported bug in development environment.',
                            'task_type' => 'testing',
                            'priority' => 'high',
                            'estimated_hours' => 2,
                            'tags' => ['bug', 'reproduction']
                        ],
                        [
                            'id' => 'root_cause_analysis',
                            'title' => 'Root Cause Analysis',
                            'description' => 'Analyze the bug to identify the root cause and affected components.',
                            'task_type' => 'analysis',
                            'priority' => 'high',
                            'estimated_hours' => 4,
                            'tags' => ['analysis', 'debugging']
                        ],
                        [
                            'id' => 'fix_implementation',
                            'title' => 'Implement Fix',
                            'description' => 'Implement the bug fix with proper code changes.',
                            'task_type' => 'development',
                            'priority' => 'high',
                            'estimated_hours' => 6,
                            'tags' => ['development', 'fix']
                        ],
                        [
                            'id' => 'unit_tests',
                            'title' => 'Write/Update Unit Tests',
                            'description' => 'Write or update unit tests to cover the bug fix.',
                            'task_type' => 'testing',
                            'priority' => 'medium',
                            'estimated_hours' => 3,
                            'tags' => ['testing', 'unit-tests']
                        ],
                        [
                            'id' => 'code_review',
                            'title' => 'Code Review',
                            'description' => 'Submit code for review and address any feedback.',
                            'task_type' => 'review',
                            'priority' => 'high',
                            'estimated_hours' => 2,
                            'tags' => ['review', 'quality']
                        ],
                        [
                            'id' => 'testing_verification',
                            'title' => 'Testing & Verification',
                            'description' => 'Test the fix in staging environment and verify it resolves the issue.',
                            'task_type' => 'testing',
                            'priority' => 'high',
                            'estimated_hours' => 4,
                            'tags' => ['testing', 'verification']
                        ]
                    ],
                    'dependencies' => [
                        [
                            'task_id' => 'root_cause_analysis',
                            'depends_on_task_id' => 'bug_reproduction',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'fix_implementation',
                            'depends_on_task_id' => 'root_cause_analysis',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'unit_tests',
                            'depends_on_task_id' => 'fix_implementation',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'code_review',
                            'depends_on_task_id' => 'unit_tests',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'testing_verification',
                            'depends_on_task_id' => 'code_review',
                            'dependency_type' => 'blocks'
                        ]
                    ]
                ],
                'variables' => [
                    'bug_id' => [
                        'required' => true,
                        'description' => 'Bug tracking ID or ticket number'
                    ],
                    'severity' => [
                        'required' => true,
                        'description' => 'Bug severity level'
                    ],
                    'affected_component' => [
                        'required' => true,
                        'description' => 'Component or module affected by the bug'
                    ]
                ],
                'tags' => ['bug', 'fix', 'development']
            ],
            [
                'name' => 'Feature Development Cycle',
                'description' => 'Complete development cycle for new feature implementation.',
                'category' => 'development',
                'template_data' => [
                    'tasks' => [
                        [
                            'id' => 'feature_specification',
                            'title' => 'Feature Specification',
                            'description' => 'Write detailed feature specification and requirements.',
                            'task_type' => 'planning',
                            'priority' => 'high',
                            'estimated_hours' => 8,
                            'tags' => ['specification', 'planning']
                        ],
                        [
                            'id' => 'technical_design',
                            'title' => 'Technical Design',
                            'description' => 'Create technical design document and architecture decisions.',
                            'task_type' => 'design',
                            'priority' => 'high',
                            'estimated_hours' => 12,
                            'tags' => ['design', 'architecture']
                        ],
                        [
                            'id' => 'backend_development',
                            'title' => 'Backend Development',
                            'description' => 'Implement backend logic and APIs.',
                            'task_type' => 'development',
                            'priority' => 'high',
                            'estimated_hours' => 32,
                            'tags' => ['backend', 'development']
                        ],
                        [
                            'id' => 'frontend_development',
                            'title' => 'Frontend Development',
                            'description' => 'Implement frontend components and user interface.',
                            'task_type' => 'development',
                            'priority' => 'high',
                            'estimated_hours' => 40,
                            'tags' => ['frontend', 'development']
                        ],
                        [
                            'id' => 'integration_testing',
                            'title' => 'Integration Testing',
                            'description' => 'Test the complete feature integration.',
                            'task_type' => 'testing',
                            'priority' => 'high',
                            'estimated_hours' => 8,
                            'tags' => ['testing', 'integration']
                        ],
                        [
                            'id' => 'user_acceptance_testing',
                            'title' => 'User Acceptance Testing',
                            'description' => 'Conduct user acceptance testing with stakeholders.',
                            'task_type' => 'testing',
                            'priority' => 'medium',
                            'estimated_hours' => 6,
                            'tags' => ['testing', 'uat']
                        ]
                    ],
                    'dependencies' => [
                        [
                            'task_id' => 'technical_design',
                            'depends_on_task_id' => 'feature_specification',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'backend_development',
                            'depends_on_task_id' => 'technical_design',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'frontend_development',
                            'depends_on_task_id' => 'technical_design',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'integration_testing',
                            'depends_on_task_id' => 'backend_development',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'integration_testing',
                            'depends_on_task_id' => 'frontend_development',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'user_acceptance_testing',
                            'depends_on_task_id' => 'integration_testing',
                            'dependency_type' => 'blocks'
                        ]
                    ]
                ],
                'variables' => [
                    'feature_name' => [
                        'required' => true,
                        'description' => 'Name of the feature being developed'
                    ],
                    'target_release' => [
                        'required' => true,
                        'description' => 'Target release version or date'
                    ],
                    'business_value' => [
                        'required' => false,
                        'description' => 'Business value or impact of the feature'
                    ]
                ],
                'tags' => ['feature', 'development', 'cycle']
            ],
            [
                'name' => 'Client Meeting Preparation',
                'description' => 'Preparation workflow for client meetings and presentations.',
                'category' => 'client_management',
                'template_data' => [
                    'tasks' => [
                        [
                            'id' => 'meeting_objectives',
                            'title' => 'Define Meeting Objectives',
                            'description' => 'Clearly define the objectives and desired outcomes of the meeting.',
                            'task_type' => 'planning',
                            'priority' => 'high',
                            'estimated_hours' => 2,
                            'tags' => ['planning', 'objectives']
                        ],
                        [
                            'id' => 'agenda_preparation',
                            'title' => 'Prepare Meeting Agenda',
                            'description' => 'Create detailed meeting agenda with time allocations.',
                            'task_type' => 'planning',
                            'priority' => 'high',
                            'estimated_hours' => 3,
                            'tags' => ['agenda', 'planning']
                        ],
                        [
                            'id' => 'materials_preparation',
                            'title' => 'Prepare Meeting Materials',
                            'description' => 'Prepare presentations, documents, and supporting materials.',
                            'task_type' => 'content',
                            'priority' => 'high',
                            'estimated_hours' => 8,
                            'tags' => ['materials', 'presentation']
                        ],
                        [
                            'id' => 'stakeholder_coordination',
                            'title' => 'Coordinate with Stakeholders',
                            'description' => 'Ensure all necessary stakeholders are aligned and prepared.',
                            'task_type' => 'coordination',
                            'priority' => 'medium',
                            'estimated_hours' => 2,
                            'tags' => ['coordination', 'stakeholders']
                        ],
                        [
                            'id' => 'meeting_facilitation',
                            'title' => 'Conduct Meeting',
                            'description' => 'Facilitate the meeting and ensure objectives are met.',
                            'task_type' => 'meeting',
                            'priority' => 'high',
                            'estimated_hours' => 2,
                            'tags' => ['meeting', 'facilitation']
                        ],
                        [
                            'id' => 'follow_up_actions',
                            'title' => 'Follow-up Actions',
                            'description' => 'Document meeting outcomes and create follow-up action items.',
                            'task_type' => 'documentation',
                            'priority' => 'medium',
                            'estimated_hours' => 3,
                            'tags' => ['follow-up', 'documentation']
                        ]
                    ],
                    'dependencies' => [
                        [
                            'task_id' => 'agenda_preparation',
                            'depends_on_task_id' => 'meeting_objectives',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'materials_preparation',
                            'depends_on_task_id' => 'agenda_preparation',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'stakeholder_coordination',
                            'depends_on_task_id' => 'agenda_preparation',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'meeting_facilitation',
                            'depends_on_task_id' => 'materials_preparation',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'meeting_facilitation',
                            'depends_on_task_id' => 'stakeholder_coordination',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'follow_up_actions',
                            'depends_on_task_id' => 'meeting_facilitation',
                            'dependency_type' => 'blocks'
                        ]
                    ]
                ],
                'variables' => [
                    'client_name' => [
                        'required' => true,
                        'description' => 'Name of the client'
                    ],
                    'meeting_type' => [
                        'required' => true,
                        'description' => 'Type of meeting (e.g., status, review, planning)'
                    ],
                    'meeting_date' => [
                        'required' => true,
                        'description' => 'Scheduled date and time of the meeting'
                    ]
                ],
                'tags' => ['client', 'meeting', 'preparation']
            ],
            [
                'name' => 'Quality Assurance Checklist',
                'description' => 'Comprehensive QA checklist for software releases.',
                'category' => 'quality_assurance',
                'template_data' => [
                    'tasks' => [
                        [
                            'id' => 'unit_testing',
                            'title' => 'Unit Testing',
                            'description' => 'Execute and verify all unit tests pass.',
                            'task_type' => 'testing',
                            'priority' => 'high',
                            'estimated_hours' => 4,
                            'tags' => ['testing', 'unit']
                        ],
                        [
                            'id' => 'integration_testing',
                            'title' => 'Integration Testing',
                            'description' => 'Test component integrations and data flow.',
                            'task_type' => 'testing',
                            'priority' => 'high',
                            'estimated_hours' => 6,
                            'tags' => ['testing', 'integration']
                        ],
                        [
                            'id' => 'performance_testing',
                            'title' => 'Performance Testing',
                            'description' => 'Conduct performance and load testing.',
                            'task_type' => 'testing',
                            'priority' => 'medium',
                            'estimated_hours' => 8,
                            'tags' => ['testing', 'performance']
                        ],
                        [
                            'id' => 'security_testing',
                            'title' => 'Security Testing',
                            'description' => 'Perform security vulnerability assessment.',
                            'task_type' => 'testing',
                            'priority' => 'high',
                            'estimated_hours' => 6,
                            'tags' => ['testing', 'security']
                        ],
                        [
                            'id' => 'user_acceptance_testing',
                            'title' => 'User Acceptance Testing',
                            'description' => 'Conduct UAT with end users.',
                            'task_type' => 'testing',
                            'priority' => 'high',
                            'estimated_hours' => 12,
                            'tags' => ['testing', 'uat']
                        ],
                        [
                            'id' => 'documentation_review',
                            'title' => 'Documentation Review',
                            'description' => 'Review and update user documentation.',
                            'task_type' => 'documentation',
                            'priority' => 'medium',
                            'estimated_hours' => 4,
                            'tags' => ['documentation', 'review']
                        ]
                    ],
                    'dependencies' => [
                        [
                            'task_id' => 'integration_testing',
                            'depends_on_task_id' => 'unit_testing',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'performance_testing',
                            'depends_on_task_id' => 'integration_testing',
                            'dependency_type' => 'relates_to'
                        ],
                        [
                            'task_id' => 'security_testing',
                            'depends_on_task_id' => 'integration_testing',
                            'dependency_type' => 'relates_to'
                        ],
                        [
                            'task_id' => 'user_acceptance_testing',
                            'depends_on_task_id' => 'integration_testing',
                            'dependency_type' => 'blocks'
                        ],
                        [
                            'task_id' => 'documentation_review',
                            'depends_on_task_id' => 'user_acceptance_testing',
                            'dependency_type' => 'relates_to'
                        ]
                    ]
                ],
                'variables' => [
                    'release_version' => [
                        'required' => true,
                        'description' => 'Version number being released'
                    ],
                    'target_environment' => [
                        'required' => true,
                        'description' => 'Target deployment environment'
                    ],
                    'qa_lead' => [
                        'required' => true,
                        'description' => 'QA team lead for this release'
                    ]
                ],
                'tags' => ['qa', 'testing', 'quality']
            ]
        ];

        foreach ($templates as $templateData) {
            TaskTemplate::create([
                'name' => $templateData['name'],
                'description' => $templateData['description'],
                'category' => $templateData['category'],
                'template_data' => $templateData['template_data'],
                'variables' => $templateData['variables'] ?? null,
                'tags' => $templateData['tags'] ?? null,
                'created_by' => 1, // Assuming admin user exists
                'updated_by' => 1,
                'is_active' => true,
                'version' => 1
            ]);
        }
    }
}