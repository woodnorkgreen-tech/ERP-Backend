<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskTemplate;
use App\Modules\UniversalTask\Services\TaskTemplateService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TaskTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TaskTemplateService $service;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TaskTemplateService();
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_can_create_a_template()
    {
        $templateData = [
            'name' => 'Test Template',
            'description' => 'A test template',
            'category' => 'testing',
            'template_data' => [
                'tasks' => [
                    [
                        'id' => 'task1',
                        'title' => 'Task 1',
                        'description' => 'First task',
                        'task_type' => 'general',
                        'priority' => 'high',
                    ],
                ],
                'dependencies' => [],
            ],
            'variables' => [
                'project_name' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'The project name',
                ],
            ],
        ];

        $template = $this->service->createTemplate($templateData, $this->user->id);

        $this->assertInstanceOf(TaskTemplate::class, $template);
        $this->assertEquals('Test Template', $template->name);
        $this->assertEquals(1, $template->version);
        $this->assertTrue($template->is_active);
    }

    /** @test */
    public function it_can_instantiate_a_simple_template()
    {
        $template = TaskTemplate::create([
            'name' => 'Simple Template',
            'description' => 'A simple template',
            'category' => 'testing',
            'version' => 1,
            'is_active' => true,
            'template_data' => [
                'tasks' => [
                    [
                        'id' => 'task1',
                        'title' => 'Task for {{project_name}}',
                        'description' => 'Work on {{project_name}}',
                        'task_type' => 'general',
                        'priority' => 'medium',
                    ],
                ],
                'dependencies' => [],
            ],
            'variables' => [
                'project_name' => [
                    'type' => 'string',
                    'required' => true,
                ],
            ],
            'created_by' => $this->user->id,
        ]);

        $result = $this->service->instantiateTemplate(
            $template,
            ['project_name' => 'Project X'],
            ['created_by' => $this->user->id]
        );

        $this->assertCount(1, $result['tasks']);
        $this->assertEquals('Task for Project X', $result['tasks'][0]->title);
        $this->assertEquals('Work on Project X', $result['tasks'][0]->description);
    }

    /** @test */
    public function it_can_instantiate_template_with_dependencies()
    {
        $template = TaskTemplate::create([
            'name' => 'Template with Dependencies',
            'description' => 'Template with task dependencies',
            'category' => 'testing',
            'version' => 1,
            'is_active' => true,
            'template_data' => [
                'tasks' => [
                    [
                        'id' => 'task1',
                        'title' => 'First Task',
                        'description' => 'Do this first',
                    ],
                    [
                        'id' => 'task2',
                        'title' => 'Second Task',
                        'description' => 'Do this second',
                    ],
                ],
                'dependencies' => [
                    [
                        'task_id' => 'task2',
                        'depends_on_task_id' => 'task1',
                        'dependency_type' => 'blocks',
                    ],
                ],
            ],
            'created_by' => $this->user->id,
        ]);

        $result = $this->service->instantiateTemplate(
            $template,
            [],
            ['created_by' => $this->user->id]
        );

        $this->assertCount(2, $result['tasks']);
        $this->assertCount(1, $result['dependencies']);
        
        $dependency = $result['dependencies'][0];
        $this->assertEquals($result['task_id_map']['task2'], $dependency->task_id);
        $this->assertEquals($result['task_id_map']['task1'], $dependency->depends_on_task_id);
    }

    /** @test */
    public function it_throws_exception_for_inactive_template()
    {
        $template = TaskTemplate::create([
            'name' => 'Inactive Template',
            'version' => 1,
            'is_active' => false,
            'template_data' => [
                'tasks' => [
                    ['id' => 'task1', 'title' => 'Task 1'],
                ],
            ],
            'created_by' => $this->user->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot instantiate inactive template');

        $this->service->instantiateTemplate($template, [], ['created_by' => $this->user->id]);
    }

    /** @test */
    public function it_throws_exception_for_missing_required_variables()
    {
        $template = TaskTemplate::create([
            'name' => 'Template with Required Var',
            'version' => 1,
            'is_active' => true,
            'template_data' => [
                'tasks' => [
                    ['id' => 'task1', 'title' => '{{project_name}}'],
                ],
            ],
            'variables' => [
                'project_name' => [
                    'required' => true,
                ],
            ],
            'created_by' => $this->user->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Required variable');

        $this->service->instantiateTemplate($template, [], ['created_by' => $this->user->id]);
    }
}
