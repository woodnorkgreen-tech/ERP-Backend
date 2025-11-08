# Materials Task Backend Integration - Complete Implementation Guide

## Overview

This document provides a comprehensive implementation guide for integrating the MaterialsTask.vue component with the Laravel backend. The Materials Task is part of the project workflow system that allows users to specify and manage materials required for project elements.

## Architecture Overview

The materials system consists of:
- **Element Templates**: Reusable templates for common project elements (Stage, Backdrop, etc.)
- **Project Elements**: Instances of templates customized for specific projects
- **Materials**: Individual items within elements with quantities and specifications
- **Task Integration**: Seamless integration with the project workflow system

## Database Schema

### Core Tables

#### 1. `element_templates`
```sql
CREATE TABLE element_templates (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) UNIQUE NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    description TEXT,
    category ENUM('structure', 'decoration', 'flooring', 'technical', 'furniture', 'branding', 'custom') NOT NULL,
    color VARCHAR(20) DEFAULT 'blue',
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### 2. `element_template_materials`
```sql
CREATE TABLE element_template_materials (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    element_template_id BIGINT NOT NULL,
    description VARCHAR(500) NOT NULL,
    unit_of_measurement VARCHAR(50) NOT NULL,
    default_quantity DECIMAL(10,2) NOT NULL,
    is_default_included BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (element_template_id) REFERENCES element_templates(id) ON DELETE CASCADE
);
```

#### 3. `task_materials_data`
```sql
CREATE TABLE task_materials_data (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    enquiry_task_id BIGINT NOT NULL,
    project_info JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (enquiry_task_id) REFERENCES enquiry_tasks(id) ON DELETE CASCADE,
    UNIQUE KEY unique_task_materials (enquiry_task_id)
);
```

#### 4. `project_elements`
```sql
CREATE TABLE project_elements (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    task_materials_data_id BIGINT NOT NULL,
    template_id VARCHAR(100),
    element_type VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    category ENUM('production', 'hire', 'outsourced') NOT NULL,
    dimensions JSON,
    is_included BOOLEAN DEFAULT TRUE,
    notes TEXT,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (task_materials_data_id) REFERENCES task_materials_data(id) ON DELETE CASCADE
);
```

#### 5. `element_materials`
```sql
CREATE TABLE element_materials (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    project_element_id BIGINT NOT NULL,
    description VARCHAR(500) NOT NULL,
    unit_of_measurement VARCHAR(50) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    is_included BOOLEAN DEFAULT TRUE,
    notes TEXT,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_element_id) REFERENCES project_elements(id) ON DELETE CASCADE
);
```

## Eloquent Models

### ElementTemplate.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ElementTemplate extends Model
{
    protected $fillable = [
        'name', 'display_name', 'description', 'category',
        'color', 'sort_order', 'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];

    public function materials(): HasMany
    {
        return $this->hasMany(ElementTemplateMaterial::class)->orderBy('sort_order');
    }
}
```

### TaskMaterialsData.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskMaterialsData extends Model
{
    protected $fillable = ['enquiry_task_id', 'project_info'];
    protected $casts = ['project_info' => 'array'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(EnquiryTask::class, 'enquiry_task_id');
    }

    public function elements(): HasMany
    {
        return $this->hasMany(ProjectElement::class)->orderBy('sort_order');
    }
}
```

## API Implementation

### MaterialsController.php

```php
<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Models\TaskMaterialsData;
use App\Models\ProjectElement;
use App\Models\ElementMaterial;
use App\Models\ElementTemplate;
use App\Models\ElementTemplateMaterial;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class MaterialsController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:' . \App\Constants\Permissions::TASK_UPDATE);
    }

    /**
     * Get materials data for a task
     */
    public function getMaterialsData(int $taskId): JsonResponse
    {
        try {
            $materialsData = TaskMaterialsData::where('enquiry_task_id', $taskId)
                ->with(['elements.materials'])
                ->first();

            if (!$materialsData) {
                return response()->json([
                    'data' => $this->getDefaultMaterialsStructure($taskId),
                    'message' => 'Materials data retrieved successfully'
                ]);
            }

            return response()->json([
                'data' => $this->formatMaterialsData($materialsData),
                'message' => 'Materials data retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve materials data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save materials data for a task
     */
    public function saveMaterialsData(Request $request, int $taskId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'projectInfo' => 'required|array',
            'projectElements' => 'required|array',
            'projectElements.*.id' => 'required|string',
            'projectElements.*.name' => 'required|string',
            'projectElements.*.category' => 'required|in:production,hire,outsourced',
            'projectElements.*.materials' => 'required|array',
            'projectElements.*.materials.*.description' => 'required|string',
            'projectElements.*.materials.*.unitOfMeasurement' => 'required|string',
            'projectElements.*.materials.*.quantity' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $materialsData = TaskMaterialsData::updateOrCreate(
                ['enquiry_task_id' => $taskId],
                [
                    'project_info' => $request->projectInfo,
                    'updated_at' => now()
                ]
            );

            // Delete existing elements and materials
            $materialsData->elements()->delete();

            // Save new elements and materials
            foreach ($request->projectElements as $elementData) {
                $element = ProjectElement::create([
                    'task_materials_data_id' => $materialsData->id,
                    'template_id' => $elementData['templateId'] ?? null,
                    'element_type' => $elementData['elementType'],
                    'name' => $elementData['name'],
                    'category' => $elementData['category'],
                    'dimensions' => $elementData['dimensions'] ?? [],
                    'is_included' => $elementData['isIncluded'] ?? true,
                    'notes' => $elementData['notes'] ?? null,
                ]);

                foreach ($elementData['materials'] as $materialData) {
                    ElementMaterial::create([
                        'project_element_id' => $element->id,
                        'description' => $materialData['description'],
                        'unit_of_measurement' => $materialData['unitOfMeasurement'],
                        'quantity' => $materialData['quantity'],
                        'is_included' => $materialData['isIncluded'] ?? true,
                        'notes' => $materialData['notes'] ?? null,
                    ]);
                }
            }

            return response()->json([
                'data' => $this->formatMaterialsData($materialsData->fresh(['elements.materials'])),
                'message' => 'Materials data saved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to save materials data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get element templates
     */
    public function getElementTemplates(): JsonResponse
    {
        try {
            $templates = ElementTemplate::where('is_active', true)
                ->with('materials')
                ->orderBy('sort_order')
                ->get();

            return response()->json([
                'data' => $templates->map(function ($template) {
                    return [
                        'id' => $template->name,
                        'name' => $template->name,
                        'displayName' => $template->display_name,
                        'description' => $template->description,
                        'category' => $template->category,
                        'color' => $template->color,
                        'order' => $template->sort_order,
                        'defaultMaterials' => $template->materials->map(function ($material) {
                            return [
                                'id' => $material->id,
                                'description' => $material->description,
                                'unitOfMeasurement' => $material->unit_of_measurement,
                                'defaultQuantity' => $material->default_quantity,
                                'isDefaultIncluded' => $material->is_default_included,
                                'order' => $material->sort_order,
                            ];
                        })
                    ];
                }),
                'message' => 'Element templates retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve element templates',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new element template
     */
    public function createElementTemplate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100|unique:element_templates,name',
            'displayName' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'required|string|in:structure,decoration,flooring,technical,furniture,branding,custom',
            'color' => 'nullable|string|max:20',
            'defaultMaterials' => 'required|array|min:1',
            'defaultMaterials.*.description' => 'required|string',
            'defaultMaterials.*.unitOfMeasurement' => 'required|string',
            'defaultMaterials.*.defaultQuantity' => 'required|numeric|min:0',
            'defaultMaterials.*.isDefaultIncluded' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $template = ElementTemplate::create([
                'name' => $request->name,
                'display_name' => $request->displayName,
                'description' => $request->description,
                'category' => $request->category,
                'color' => $request->color ?? 'blue',
                'sort_order' => ElementTemplate::max('sort_order') + 1
            ]);

            // Create default materials
            foreach ($request->defaultMaterials as $materialData) {
                ElementTemplateMaterial::create([
                    'element_template_id' => $template->id,
                    'description' => $materialData['description'],
                    'unit_of_measurement' => $materialData['unitOfMeasurement'],
                    'default_quantity' => $materialData['defaultQuantity'],
                    'is_default_included' => $materialData['isDefaultIncluded'] ?? true,
                    'sort_order' => $materialData['order'] ?? 0
                ]);
            }

            return response()->json([
                'data' => $template->load('materials'),
                'message' => 'Element template created successfully'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create element template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get default materials structure for new tasks
     */
    private function getDefaultMaterialsStructure(int $taskId): array
    {
        $task = \App\Modules\Projects\Models\EnquiryTask::with('enquiry')->find($taskId);

        return [
            'projectInfo' => [
                'projectId' => $task->enquiry->enquiry_number ?? "ENQ-{$taskId}",
                'enquiryTitle' => $task->enquiry->title ?? 'Untitled Project',
                'clientName' => $task->enquiry->client->full_name ?? 'Unknown Client',
                'eventVenue' => $task->enquiry->venue ?? 'Venue TBC',
                'setupDate' => $task->enquiry->expected_delivery_date ?? 'Date TBC',
                'setDownDate' => 'TBC'
            ],
            'projectElements' => [],
            'availableElements' => $this->getElementTemplates()->getData()->data
        ];
    }

    /**
     * Format materials data for frontend
     */
    private function formatMaterialsData(TaskMaterialsData $materialsData): array
    {
        return [
            'projectInfo' => $materialsData->project_info,
            'projectElements' => $materialsData->elements->map(function ($element) {
                return [
                    'id' => $element->id,
                    'templateId' => $element->template_id,
                    'elementType' => $element->element_type,
                    'name' => $element->name,
                    'category' => $element->category,
                    'dimensions' => $element->dimensions ?? ['length' => '', 'width' => '', 'height' => ''],
                    'isIncluded' => $element->is_included,
                    'materials' => $element->materials->map(function ($material) {
                        return [
                            'id' => $material->id,
                            'description' => $material->description,
                            'unitOfMeasurement' => $material->unit_of_measurement,
                            'quantity' => $material->quantity,
                            'isIncluded' => $material->is_included,
                            'notes' => $material->notes,
                            'createdAt' => $material->created_at,
                            'updatedAt' => $material->updated_at,
                        ];
                    }),
                    'notes' => $element->notes,
                    'addedAt' => $element->created_at,
                ];
            }),
            'availableElements' => $this->getElementTemplates()->getData()->data
        ];
    }
}
```

### API Routes

Add to `routes/api.php`:

```php
// Materials management routes
Route::prefix('projects/tasks/{taskId}/materials')->group(function () {
    Route::get('/', [MaterialsController::class, 'getMaterialsData']);
    Route::post('/', [MaterialsController::class, 'saveMaterialsData']);
});

// Element templates
Route::get('projects/element-templates', [MaterialsController::class, 'getElementTemplates']);
Route::post('projects/element-templates', [MaterialsController::class, 'createElementTemplate']);
```

## Frontend Implementation

### API Service (materialsService.ts)

```typescript
import axios from '@/plugins/axios'

export interface MaterialsTaskData {
  projectInfo: ProjectInfo
  projectElements: ProjectElement[]
  availableElements: ElementTemplate[]
}

export interface ProjectInfo {
  projectId: string
  enquiryTitle: string
  clientName: string
  eventVenue: string
  setupDate: string
  setDownDate: string
}

export interface ProjectElement {
  id: string
  templateId?: string
  elementType: string
  name: string
  category: 'production' | 'hire' | 'outsourced'
  dimensions: {
    length: string
    width: string
    height: string
  }
  isIncluded: boolean
  materials: MaterialItem[]
  notes?: string
  addedAt: Date
}

export interface MaterialItem {
  id: string
  description: string
  unitOfMeasurement: string
  quantity: number
  isIncluded: boolean
  notes?: string
  createdAt: Date
  updatedAt: Date
}

export interface ElementTemplate {
  id: string
  name: string
  displayName: string
  description: string
  category: string
  color: string
  order: number
  defaultMaterials: MaterialTemplate[]
}

export interface MaterialTemplate {
  id: string
  description: string
  unitOfMeasurement: string
  defaultQuantity: number
  isDefaultIncluded: boolean
  order: number
}

export interface CreateElementTemplateRequest {
  name: string
  displayName: string
  description?: string
  category: string
  color?: string
  defaultMaterials: MaterialTemplate[]
}

export class MaterialsService {
  /**
   * Get materials data for a task
   */
  static async getMaterialsData(taskId: number): Promise<MaterialsTaskData> {
    const response = await axios.get(`/api/projects/tasks/${taskId}/materials`)
    return response.data.data
  }

  /**
   * Save materials data for a task
   */
  static async saveMaterialsData(taskId: number, data: MaterialsTaskData): Promise<MaterialsTaskData> {
    const response = await axios.post(`/api/projects/tasks/${taskId}/materials`, data)
    return response.data.data
  }

  /**
   * Get available element templates
   */
  static async getElementTemplates(): Promise<ElementTemplate[]> {
    const response = await axios.get('/api/projects/element-templates')
    return response.data.data
  }

  /**
   * Create a new element template
   */
  static async createElementTemplate(data: CreateElementTemplateRequest): Promise<ElementTemplate> {
    const response = await axios.post('/api/projects/element-templates', data)
    return response.data.data
  }
}
```

### MaterialsTask Component Updates

Update the MaterialsTask.vue component to integrate with the backend:

```typescript
// Add imports
import { MaterialsService, MaterialsTaskData } from '../../services/materialsService'

// Replace reactive materialsData initialization
const materialsData = reactive<MaterialsTaskData>({
  projectInfo: initializeProjectInfo(),
  projectElements: [],
  availableElements: []
})

// Add reactive state
const isLoading = ref(true)
const isSaving = ref(false)
const error = ref<string | null>(null)

// Load data on component mount
const loadMaterialsData = async () => {
  try {
    error.value = null
    isLoading.value = true
    const data = await MaterialsService.getMaterialsData(props.task.id)
    Object.assign(materialsData, data)
    initializeCollapsedState()
  } catch (err: any) {
    error.value = err.response?.data?.message || 'Failed to load materials data'
    console.error('Failed to load materials data:', err)
    // Fallback to default data
    Object.assign(materialsData, {
      projectInfo: initializeProjectInfo(),
      projectElements: initializeProjectElements(),
      availableElements: DEFAULT_ELEMENT_TEMPLATES
    })
  } finally {
    isLoading.value = false
  }
}

// Update save function
const saveMaterialsList = async () => {
  isSaving.value = true
  try {
    error.value = null
    const savedData = await MaterialsService.saveMaterialsData(props.task.id, materialsData)
    Object.assign(materialsData, savedData)
    lastSaved.value = new Date()
    console.log('Materials list saved successfully')
  } catch (err: any) {
    error.value = err.response?.data?.message || 'Failed to save materials list'
    console.error('Failed to save materials list:', err)
  } finally {
    isSaving.value = false
  }
}

// Load data when component mounts
onMounted(() => {
  loadMaterialsData()
})
```

### MaterialsModal Component Updates

Update the MaterialsModal.vue to persist new element types:

```typescript
// Update imports
import { MaterialsService, ElementTemplate } from '../../services/materialsService'

// Update saveNewElementType function
const saveNewElementType = async () => {
  if (!validateNewElementType()) {
    return
  }

  try {
    const templateData = {
      name: newElementType.name.toLowerCase().replace(/\s+/g, '-'),
      displayName: newElementType.name,
      category: newElementType.category,
      color: 'blue',
      defaultMaterials: [] // Empty for now, can be expanded later
    }

    const newTemplate = await MaterialsService.createElementTemplate(templateData)

    // Add to available types
    availableElementTypes.value.push({
      id: newTemplate.name,
      name: newTemplate.name,
      displayName: newTemplate.display_name,
      category: newTemplate.category
    })

    // Select the new type in the main form
    elementForm.elementType = newTemplate.name

    closeAddElementTypeModal()
  } catch (error: any) {
    console.error('Failed to create element template:', error)
    // Handle error - show user feedback
    if (error.response?.data?.errors?.name) {
      newElementTypeErrors.name = error.response.data.errors.name[0]
    }
  }
}
```

## Database Seeders

### ElementTemplatesSeeder.php

```php
<?php

namespace Database\Seeders;

use App\Models\ElementTemplate;
use App\Models\ElementTemplateMaterial;
use Illuminate\Database\Seeder;

class ElementTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'stage',
                'display_name' => 'STAGE',
                'description' => 'Main stage structure and components',
                'category' => 'structure',
                'color' => 'green',
                'materials' => [
                    ['description' => 'Stage Boards', 'unit' => 'Pcs', 'quantity' => 8, 'included' => true],
                    ['description' => 'Stage Legs', 'unit' => 'Pcs', 'quantity' => 16, 'included' => true],
                    ['description' => 'Stage Screws', 'unit' => 'Pcs', 'quantity' => 32, 'included' => true],
                    ['description' => 'Stage Brackets', 'unit' => 'Pcs', 'quantity' => 8, 'included' => true],
                ]
            ],
            [
                'name' => 'stage-skirting',
                'display_name' => 'STAGE SKIRTING',
                'description' => 'Stage skirting and decorative elements',
                'category' => 'decoration',
                'color' => 'blue',
                'materials' => [
                    ['description' => 'Skirting Fabric', 'unit' => 'Mtrs', 'quantity' => 12, 'included' => true],
                    ['description' => 'Skirting Clips', 'unit' => 'Pcs', 'quantity' => 24, 'included' => true],
                    ['description' => 'Velcro Strips', 'unit' => 'Mtrs', 'quantity' => 6, 'included' => false],
                ]
            ],
            [
                'name' => 'stage-backdrop',
                'display_name' => 'STAGE BACKDROP',
                'description' => 'Backdrop structure and materials',
                'category' => 'decoration',
                'color' => 'purple',
                'materials' => [
                    ['description' => 'Backdrop Frame', 'unit' => 'Pcs', 'quantity' => 1, 'included' => true],
                    ['description' => 'Backdrop Fabric', 'unit' => 'sqm', 'quantity' => 20, 'included' => true],
                    ['description' => 'Backdrop Weights', 'unit' => 'Pcs', 'quantity' => 4, 'included' => true],
                ]
            ],
            [
                'name' => 'entrance-arc',
                'display_name' => 'ENTRANCE ARC',
                'description' => 'Entrance archway and decorations',
                'category' => 'decoration',
                'color' => 'orange',
                'materials' => [
                    ['description' => 'Arc Frame', 'unit' => 'Pcs', 'quantity' => 1, 'included' => true],
                    ['description' => 'Decorative Flowers', 'unit' => 'Pcs', 'quantity' => 50, 'included' => false],
                    ['description' => 'Arc Fabric Draping', 'unit' => 'Mtrs', 'quantity' => 8, 'included' => true],
                ]
            ],
            [
                'name' => 'walkway-dance-floor',
                'display_name' => 'WALKWAY AND DANCE FLOOR',
                'description' => 'Walkway and dance floor components',
                'category' => 'flooring',
                'color' => 'teal',
                'materials' => [
                    ['description' => 'Dance Floor Panels', 'unit' => 'sqm', 'quantity' => 36, 'included' => true],
                    ['description' => 'Walkway Carpet', 'unit' => 'Mtrs', 'quantity' => 15, 'included' => true],
                    ['description' => 'Floor Marking Tape', 'unit' => 'Mtrs', 'quantity' => 20, 'included' => false],
                ]
            ],
        ];

        foreach ($templates as $index => $templateData) {
            $template = ElementTemplate::create([
                'name' => $templateData['name'],
                'display_name' => $templateData['display_name'],
                'description' => $templateData['description'],
                'category' => $templateData['category'],
                'color' => $templateData['color'],
                'sort_order' => $index + 1,
            ]);

            foreach ($templateData['materials'] as $materialIndex => $materialData) {
                ElementTemplateMaterial::create([
                    'element_template_id' => $template->id,
                    'description' => $materialData['description'],
                    'unit_of_measurement' => $materialData['unit'],
                    'default_quantity' => $materialData['quantity'],
                    'is_default_included' => $materialData['included'],
                    'sort_order' => $materialIndex + 1,
                ]);
            }
        }
    }
}
```

## Testing

### Unit Tests

Create `tests/Unit/MaterialsControllerTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\ElementTemplate;
use App\Models\TaskMaterialsData;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MaterialsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_element_template()
    {
        $templateData = [
            'name' => 'test-template',
            'displayName' => 'Test Template',
            'description' => 'A test template',
            'category' => 'structure',
            'color' => 'blue',
            'defaultMaterials' => [
                [
                    'description' => 'Test Material',
                    'unitOfMeasurement' => 'Pcs',
                    'defaultQuantity' => 10,
                    'isDefaultIncluded' => true
                ]
            ]
        ];

        $response = $this->postJson('/api/projects/element-templates', $templateData);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'data' => [
                        'id',
                        'name',
                        'display_name',
                        'materials'
                    ],
                    'message'
                ]);

        $this->assertDatabaseHas('element_templates', [
            'name' => 'test-template',
            'display_name' => 'Test Template'
        ]);
    }
}
```

### Feature Tests

Create `tests/Feature/MaterialsWorkflowTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\EnquiryTask;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MaterialsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_materials_data()
    {
        $user = User::factory()->create();
        $task = EnquiryTask::factory()->create();

        $materialsData = [
            'projectInfo' => [
                'projectId' => 'TEST-001',
                'enquiryTitle' => 'Test Project',
                'clientName' => 'Test Client',
                'eventVenue' => 'Test Venue',
                'setupDate' => '2024-01-01',
                'setDownDate' => '2024-01-02'
            ],
            'projectElements' => [
                [
                    'id' => 'element-1',
                    'name' => 'Test Stage',
                    'category' => 'production',
                    'elementType' => 'stage',
                    'isIncluded' => true,
                    'materials' => [
                        [
                            'description' => 'Test Material',
                            'unitOfMeasurement' => 'Pcs',
                            'quantity' => 10,
                            'isIncluded' => true
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->actingAs($user)
                        ->postJson("/api/projects/tasks/{$task->id}/materials", $materialsData);

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        'projectInfo',
                        'projectElements'
                    ],
                    'message'
                ]);
    }
}
```

## Deployment Checklist

### Pre-deployment:
- [ ] Run database migrations: `php artisan migrate`
- [ ] Run seeders: `php artisan db:seed --class=ElementTemplatesSeeder`
- [ ] Update API routes in `routes/api.php`
- [ ] Verify permission constants are defined
- [ ] Test API endpoints with Postman

### Post-deployment:
- [ ] Verify element templates are seeded correctly
- [ ] Test complete materials task workflow
- [ ] Check frontend-backend data synchronization
- [ ] Validate print functionality
- [ ] Test "Create New Element Type" feature
- [ ] Verify error handling and user feedback

### Rollback Plan:
- [ ] Database migration rollback: `php artisan migrate:rollback`
- [ ] Remove API routes if needed
- [ ] Frontend code reversion strategy
- [ ] Data cleanup procedures

## API Endpoints Summary

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/projects/tasks/{taskId}/materials` | Get materials data for a task |
| POST | `/api/projects/tasks/{taskId}/materials` | Save materials data for a task |
| GET | `/api/projects/element-templates` | Get all available element templates |
| POST | `/api/projects/element-templates` | Create a new element template |

## Data Flow

1. **Task Load**: Component loads existing materials data or initializes defaults
2. **User Interaction**: User adds/edits elements and materials through modal
3. **Data Save**: Frontend sends data to backend for validation and persistence
4. **Task Completion**: User marks task complete, triggering workflow progression
5. **Print/Export**: User can print materials list for procurement

## Security Considerations

- All endpoints require authentication
- Permission checks using `TASK_UPDATE` permission
- Input validation on all API endpoints
- SQL injection prevention through Eloquent ORM
- XSS prevention through proper data sanitization

## Performance Optimizations

- Database indexes on frequently queried columns
- Eager loading of related models
- Pagination for large datasets (future enhancement)
- Caching for element templates (future enhancement)

## Future Enhancements

- Bulk import/export of materials
- Integration with procurement system
- Cost calculation and budgeting
- Supplier management
- Inventory tracking
- Mobile-responsive improvements

---

This implementation provides a complete, production-ready materials management system that integrates seamlessly with the existing project workflow.
