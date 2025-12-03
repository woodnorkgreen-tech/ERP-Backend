<?php

namespace App\Modules\UniversalTask\Services;

use App\Modules\UniversalTask\Models\Task;
use App\Modules\UniversalTask\Models\TaskTemplate;
use App\Modules\UniversalTask\Models\TaskDependency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskTemplateService
{
    /**
     * Instantiate a task template, creating all tasks and dependencies.
     * 
     * @param TaskTemplate $template The template to instantiate
     * @param array $variables Variable values for substitution (e.g., ['project_name' => 'Project X'])
     * @param array $context Additional context for task creation (e.g., taskable_type, taskable_id, department_id)
     * @return array Array containing created tasks and dependencies
     * @throws \Exception If instantiation fails
     */
    public function instantiateTemplate(TaskTemplate $template, array $variables = [], array $context = []): array
    {
        // Validate template is active
        if (!$template->is_active) {
            throw new \InvalidArgumentException('Cannot instantiate inactive template');
        }

        // Validate template data
        if (!$template->validateTemplateData()) {
            throw new \InvalidArgumentException('Invalid template data structure');
        }

        // Validate required variables are provided
        $this->validateVariables($template, $variables);

        $createdTasks = [];
        $createdDependencies = [];
        $taskIdMap = []; // Maps template task IDs to created task IDs

        DB::beginTransaction();

        try {
            // Step 1: Create all tasks from template
            $templateTasks = $template->template_data['tasks'] ?? [];
            
            foreach ($templateTasks as $templateTask) {
                $taskData = $this->prepareTaskData($templateTask, $variables, $context);
                
                // Create the task
                $task = Task::create($taskData);
                
                // Store mapping from template task ID to created task ID
                $templateTaskId = $templateTask['id'] ?? $templateTask['template_id'] ?? null;
                if ($templateTaskId) {
                    $taskIdMap[$templateTaskId] = $task->id;
                }
                
                $createdTasks[] = $task;
            }

            // Step 2: Create parent-child relationships for subtasks
            foreach ($templateTasks as $index => $templateTask) {
                if (isset($templateTask['parent_id']) && $templateTask['parent_id']) {
                    $templateParentId = $templateTask['parent_id'];
                    
                    if (isset($taskIdMap[$templateParentId])) {
                        $createdTasks[$index]->parent_task_id = $taskIdMap[$templateParentId];
                        $createdTasks[$index]->save();
                    }
                }
            }

            // Step 3: Create dependencies from template
            $templateDependencies = $template->template_data['dependencies'] ?? [];
            
            foreach ($templateDependencies as $templateDependency) {
                $dependencyData = $this->prepareDependencyData($templateDependency, $taskIdMap);
                
                if ($dependencyData) {
                    $dependency = TaskDependency::create($dependencyData);
                    $createdDependencies[] = $dependency;
                }
            }

            DB::commit();

            Log::info('Template instantiated successfully', [
                'template_id' => $template->id,
                'template_name' => $template->name,
                'template_version' => $template->version,
                'tasks_created' => count($createdTasks),
                'dependencies_created' => count($createdDependencies),
            ]);

            return [
                'tasks' => $createdTasks,
                'dependencies' => $createdDependencies,
                'task_id_map' => $taskIdMap,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Template instantiation failed', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw new \Exception('Failed to instantiate template: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate that all required variables are provided.
     * 
     * @param TaskTemplate $template
     * @param array $variables
     * @throws \InvalidArgumentException
     */
    protected function validateVariables(TaskTemplate $template, array $variables): void
    {
        $templateVariables = $template->variables ?? [];
        
        foreach ($templateVariables as $varName => $varConfig) {
            $isRequired = $varConfig['required'] ?? false;
            
            if ($isRequired && !isset($variables[$varName])) {
                throw new \InvalidArgumentException(
                    "Required variable '{$varName}' not provided for template instantiation"
                );
            }
        }
    }

    /**
     * Prepare task data from template task definition with variable substitution.
     * 
     * @param array $templateTask
     * @param array $variables
     * @param array $context
     * @return array
     */
    protected function prepareTaskData(array $templateTask, array $variables, array $context): array
    {
        $taskData = [
            'title' => $this->substituteVariables($templateTask['title'], $variables),
            'description' => $this->substituteVariables($templateTask['description'] ?? '', $variables),
            'task_type' => $templateTask['task_type'] ?? null,
            'status' => $templateTask['status'] ?? 'pending',
            'priority' => $templateTask['priority'] ?? 'medium',
            'estimated_hours' => $templateTask['estimated_hours'] ?? null,
            'tags' => $templateTask['tags'] ?? null,
            'metadata' => array_merge(
                $templateTask['metadata'] ?? [],
                [
                    'created_from_template' => true,
                    'template_id' => $context['template_id'] ?? null,
                    'template_version' => $context['template_version'] ?? null,
                ]
            ),
        ];

        // Add context data (taskable, department, assignee, creator)
        if (isset($context['taskable_type'])) {
            $taskData['taskable_type'] = $context['taskable_type'];
        }
        if (isset($context['taskable_id'])) {
            $taskData['taskable_id'] = $context['taskable_id'];
        }
        if (isset($context['department_id'])) {
            $taskData['department_id'] = $context['department_id'];
        }
        if (isset($context['assigned_user_id'])) {
            $taskData['assigned_user_id'] = $templateTask['assigned_user_id'] ?? $context['assigned_user_id'];
        }
        if (isset($context['created_by'])) {
            $taskData['created_by'] = $context['created_by'];
        }

        // Handle due date with offset from instantiation time
        if (isset($templateTask['due_date_offset_days'])) {
            $taskData['due_date'] = now()->addDays($templateTask['due_date_offset_days']);
        } elseif (isset($templateTask['due_date'])) {
            $taskData['due_date'] = $templateTask['due_date'];
        }

        return $taskData;
    }

    /**
     * Prepare dependency data from template dependency definition.
     * 
     * @param array $templateDependency
     * @param array $taskIdMap
     * @return array|null
     */
    protected function prepareDependencyData(array $templateDependency, array $taskIdMap): ?array
    {
        $taskId = $templateDependency['task_id'] ?? null;
        $dependsOnTaskId = $templateDependency['depends_on_task_id'] ?? null;

        // Both task IDs must be present in the map
        if (!$taskId || !$dependsOnTaskId) {
            return null;
        }

        if (!isset($taskIdMap[$taskId]) || !isset($taskIdMap[$dependsOnTaskId])) {
            return null;
        }

        return [
            'task_id' => $taskIdMap[$taskId],
            'depends_on_task_id' => $taskIdMap[$dependsOnTaskId],
            'dependency_type' => $templateDependency['dependency_type'] ?? 'blocks',
        ];
    }

    /**
     * Substitute variables in a string.
     * Variables are in the format {{variable_name}}.
     * 
     * @param string $text
     * @param array $variables
     * @return string
     */
    protected function substituteVariables(string $text, array $variables): string
    {
        if (empty($text)) {
            return $text;
        }

        foreach ($variables as $key => $value) {
            // Support both {{variable}} and {variable} formats
            $text = str_replace('{{' . $key . '}}', $value, $text);
            $text = str_replace('{' . $key . '}', $value, $text);
        }

        return $text;
    }

    /**
     * Create a new template.
     * 
     * @param array $data
     * @param int $userId
     * @return TaskTemplate
     */
    public function createTemplate(array $data, int $userId): TaskTemplate
    {
        $templateData = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'template_data' => $data['template_data'],
            'variables' => $data['variables'] ?? null,
            'tags' => $data['tags'] ?? null,
            'created_by' => $userId,
            'updated_by' => $userId,
            'is_active' => true,
            'version' => 1,
        ];

        return TaskTemplate::create($templateData);
    }

    /**
     * Update a template by creating a new version.
     * 
     * @param TaskTemplate $template
     * @param array $data
     * @param int $userId
     * @return TaskTemplate
     */
    public function updateTemplate(TaskTemplate $template, array $data, int $userId): TaskTemplate
    {
        return $template->createNewVersion($data, $userId);
    }

    /**
     * Delete a template (soft delete).
     * 
     * @param TaskTemplate $template
     * @return bool
     */
    public function deleteTemplate(TaskTemplate $template): bool
    {
        return $template->delete();
    }

    /**
     * Get all active templates.
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveTemplates()
    {
        return TaskTemplate::active()->latest()->get();
    }

    /**
     * Get templates by category.
     * 
     * @param string $category
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getTemplatesByCategory(string $category)
    {
        return TaskTemplate::active()->byCategory($category)->latest()->get();
    }
}
