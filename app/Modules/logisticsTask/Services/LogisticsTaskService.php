<?php

namespace App\Modules\logisticsTask\Services;

use App\Modules\logisticsTask\Models\LogisticsTask;
use App\Modules\logisticsTask\Models\TransportItem;
use App\Modules\logisticsTask\Models\LogisticsChecklist;
use App\Modules\Projects\Models\EnquiryTask;
use App\Models\TaskMaterialsData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class LogisticsTaskService
{
    /**
     * Get logistics data for a specific task
     */
    public function getLogisticsForTask(int $taskId): ?array
    {
        $logisticsTask = LogisticsTask::where('task_id', $taskId)
            ->with(['transportItems', 'checklist.checklistItems', 'task.enquiry.projectOfficer'])
            ->first();

        if (!$logisticsTask) {
            return null;
        }

        // Prepare logistics planning with defaults if empty
        $planning = $logisticsTask->logistics_planning ?? [];
        if (empty($planning)) {
            $planning = [
                'vehicle_identification' => '',
                'driver_name' => '',
                'route' => [
                    'destination' => $logisticsTask->task->enquiry->venue ?? 'TBC',
                ],
                'timeline' => [
                    'loading_time' => null,
                    'departure_time' => null,
                    'setup_start_time' => null,
                    'setup_start_hour' => null,
                ]
            ];
        } else {
            // Ensure nested objects exist
            if (!isset($planning['route'])) {
                $planning['route'] = [
                    'destination' => $logisticsTask->task->enquiry->venue ?? 'TBC',
                ];
            }
            if (!isset($planning['timeline'])) {
                $planning['timeline'] = [
                    'loading_time' => null,
                    'departure_time' => null,
                    'setup_start_time' => null,
                    'setup_start_hour' => null,
                ];
            }
        }

        return [
            'id' => $logisticsTask->id,
            'task_id' => $logisticsTask->task_id,
            'project_officer' => [
                'name' => $logisticsTask->task->enquiry->projectOfficer->name ?? $logisticsTask->task->enquiry->project_officer_name ?? 'N/A',
                'email' => $logisticsTask->task->enquiry->projectOfficer->email ?? null,
            ],
            'logistics_planning' => $planning,
            'transport_items' => $logisticsTask->transportItems->map(function ($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'category' => $item->category,
                    'main_category' => $item->main_category,
                    'is_returnable' => $item->is_returnable,
                    'sub_type' => $item->sub_type,
                    'element_category' => $item->element_category,
                    'source' => $item->source,
                    'weight' => $item->weight,
                    'special_handling' => $item->special_handling,
                ];
            })->toArray(),
            'checklist' => $this->formatChecklistData($logisticsTask->checklist->first()),
            'status' => $logisticsTask->status,
        ];
    }

    /**
     * Save logistics planning data
     */
    public function saveLogisticsPlanning(int $taskId, array $data): LogisticsTask
    {
        return DB::transaction(function () use ($taskId, $data) {
            $logisticsTask = LogisticsTask::firstOrCreate(
                ['task_id' => $taskId],
                [
                    'project_id' => $this->getProjectIdFromTask($taskId),
                    'created_by' => auth()->id(),
                ]
            );

            $logisticsTask->update([
                'logistics_planning' => $data,
                'updated_by' => auth()->id(),
            ]);

            return $logisticsTask->fresh();
        });
    }

    /**
     * Get transport items for a task
     */
    public function getTransportItems(int $taskId): array
    {
        $logisticsTask = LogisticsTask::where('task_id', $taskId)->first();

        if (!$logisticsTask) {
            return [];
        }

        return $logisticsTask->transportItems->map(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'category' => $item->category,
                'main_category' => $item->main_category,
                'is_returnable' => $item->is_returnable,
                'sub_type' => $item->sub_type,
                'element_category' => $item->element_category,
                'source' => $item->source,
                'weight' => $item->weight,
                'special_handling' => $item->special_handling,
            ];
        })->toArray();
    }

    /**
     * Add a transport item
     */
    public function addTransportItem(int $taskId, array $data): TransportItem
    {
        return DB::transaction(function () use ($taskId, $data) {
            $logisticsTask = LogisticsTask::firstOrCreate(
                ['task_id' => $taskId],
                [
                    'project_id' => $this->getProjectIdFromTask($taskId),
                    'created_by' => auth()->id(),
                ]
            );

            return $logisticsTask->transportItems()->create([
                ...$data,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * Update a transport item
     */
    public function updateTransportItem(int $taskId, int $itemId, array $data): TransportItem
    {
        $item = TransportItem::where('id', $itemId)
            ->whereHas('logisticsTask', fn ($query) => $query->where('task_id', $taskId))
            ->firstOrFail();
        $item->update($data);
        return $item->fresh();
    }

    /**
     * Remove a transport item
     */
    public function removeTransportItem(int $taskId, int $itemId): bool
    {
        $item = TransportItem::where('id', $itemId)
            ->whereHas('logisticsTask', fn ($query) => $query->where('task_id', $taskId))
            ->firstOrFail();
        return $item->delete();
    }

    /**
     * Import production elements as transport items (Sourced from Materials Task)
     */
    public function importProductionElements(int $taskId, ?array $elementIds = null): array
    {
        return DB::transaction(function () use ($taskId, $elementIds) {
            try {
                // Get the enquiry task to find project
                $task = EnquiryTask::findOrFail($taskId);
                $enquiryId = $task->project_enquiry_id;

                \Log::info('Importing elements from Materials Task', [
                    'taskId' => $taskId,
                    'enquiryId' => $enquiryId,
                ]);

                // Find the materials task in the same enquiry
                $materialsTask = EnquiryTask::where('project_enquiry_id', $enquiryId)
                    ->where('type', 'materials')
                    ->first();

                if (!$materialsTask) {
                    \Log::warning('No materials task found for enquiry', [
                        'enquiryId' => $enquiryId,
                    ]);
                    return [];
                }

                \Log::info('Found materials task', [
                    'materialsTaskId' => $materialsTask->id,
                ]);

                // Get materials data
                $materialsData = TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)
                    ->with(['elements.materials.libraryMaterial'])
                    ->first();

                if (!$materialsData) {
                    \Log::warning('No materials data found for materials task', [
                        'materialsTaskId' => $materialsTask->id
                    ]);
                    return [];
                }

                $elements = $materialsData->elements; // ProjectElements

                // A caller-supplied selection restricts import to those specific
                // elements (used by the "push to logistics" action from the
                // Materials task) instead of the default "import everything
                // included" behaviour. Filtering against $materialsData->elements
                // — already scoped to this enquiry's own materials data — means
                // an id belonging to another enquiry is simply excluded here,
                // never imported.
                if ($elementIds !== null) {
                    $elements = $elements->whereIn('id', $elementIds);
                    if ($elements->isEmpty()) {
                        throw new \Exception('None of the selected elements could be found on this Materials task.');
                    }
                    // The loop below silently skips excluded elements — fail loudly
                    // here instead, since a user who explicitly selected an element
                    // and got nothing pushed needs to know why, not a quiet no-op.
                    if ($elements->where('is_included', true)->isEmpty()) {
                        throw new \Exception('The selected element(s) are excluded from this materials list and cannot be pushed. Include them first.');
                    }
                }

                $elementCount = $elements->count();

                \Log::info('Found materials data', [
                    'materialsDataId' => $materialsData->id,
                    'elementCount' => $elementCount
                ]);

                if ($elementCount === 0) {
                    \Log::info('No elements to import');
                    return [];
                }

                // Get or create logistics task
                $logisticsTask = LogisticsTask::firstOrCreate(
                    ['task_id' => $taskId],
                    [
                        'project_id' => $this->getProjectIdFromTask($taskId),
                        'created_by' => auth()->id(),
                    ]
                );

                // Get existing transport item names to avoid duplicates
                $existingItems = $logisticsTask->transportItems()
                    ->where('category', 'production')
                    ->pluck('name')
                    ->toArray();

                $importedItems = [];

                // Import each project element
                foreach ($elements as $element) {
                    // Only import if included
                    if (!$element->is_included) {
                        continue;
                    }

                    // Construct description from notes, dimensions, and materials (particulars)
                    $descriptionParts = [];
                    if ($element->notes) $descriptionParts[] = $element->notes;
                    if ($element->dimensions && is_array($element->dimensions)) {
                        $dimStr = implode(' x ', array_filter($element->dimensions));
                        if ($dimStr) $descriptionParts[] = "Dimensions: " . $dimStr;
                    }

                    // Add materials (particulars)
                    if ($element->materials && $element->materials->count() > 0) {
                        $materialParts = ["Particulars:"];
                        foreach ($element->materials as $mat) {
                            $matName = $mat->description;
                            if (!$matName && $mat->libraryMaterial) {
                                $matName = $mat->libraryMaterial->name;
                            }
                            $qty = $mat->quantity + 0; // trim trailing zeros
                            $unit = $mat->unit_of_measurement;
                            $materialParts[] = "- {$qty} {$unit} {$matName}";
                        }
                        $descriptionParts[] = implode("\n", $materialParts);
                    }
                    
                    $description = implode("\n\n", $descriptionParts);

                    // Determine category mapping
                    $mainCategory = 'PRODUCTION';
                    if ($element->category === 'hire') {
                        $mainCategory = 'STORES';
                    }
                    
                    // Check if item exists by source
                    $existingItem = $logisticsTask->transportItems()
                        ->where('source', 'project_element_' . $element->id)
                        ->first();

                    try {
                        if ($existingItem) {
                            // Update existing item
                            $existingItem->update([
                                'name' => $element->name, // Update name in case it changed
                                'description' => $description ?: null,
                                'element_category' => $element->category,
                                'is_returnable' => true, // Imported elements are generally returnable
                                'sub_type' => $element->category === 'hire' ? 'hire' : null,
                            ]);
                            $transportItem = $existingItem;
                            
                            \Log::info('Updated project element', [
                                'elementName' => $element->name,
                                'transportItemId' => $transportItem->id
                            ]);
                        } else {
                            // Create new item
                            $transportItem = $logisticsTask->transportItems()->create([
                                'name' => $element->name,
                                'description' => $description ?: null,
                                'quantity' => 1, 
                                'unit' => 'item',
                                'category' => 'production', 
                                'main_category' => $mainCategory,
                                'is_returnable' => true,
                                'sub_type' => $element->category === 'hire' ? 'hire' : null,
                                'element_category' => $element->category,
                                'source' => 'project_element_' . $element->id,
                                'weight' => null,
                                'special_handling' => null,
                                'created_by' => auth()->id(),
                            ]);

                            \Log::info('Imported project element', [
                                'elementName' => $element->name,
                                'transportItemId' => $transportItem->id
                            ]);
                        }

                        $importedItems[] = [
                            'id' => $transportItem->id,
                            'name' => $transportItem->name,
                            'description' => $transportItem->description,
                            'quantity' => $transportItem->quantity,
                            'unit' => $transportItem->unit,
                            'category' => $transportItem->category,
                            'main_category' => $transportItem->main_category,
                            'is_returnable' => $transportItem->is_returnable,
                            'sub_type' => $transportItem->sub_type,
                            'element_category' => $transportItem->element_category,
                            'source' => $transportItem->source,
                            'weight' => $transportItem->weight,
                            'special_handling' => $transportItem->special_handling,
                        ];

                        \Log::info('Imported project element', [
                            'elementName' => $element->name,
                            'transportItemId' => $transportItem->id
                        ]);
                    } catch (\Exception $e) {
                        \Log::error('Failed to create transport item for project element', [
                            'elementId' => $element->id,
                            'elementName' => $element->name,
                            'error' => $e->getMessage()
                        ]);
                        throw $e;
                    }
                }

                \Log::info('Successfully imported elements from Materials Task', [
                    'importedCount' => count($importedItems),
                    'taskId' => $taskId
                ]);

                // --- Part 2: Import from Project Enquiry Deliverables ---
                // Skipped for an explicit selection: the caller asked for specific
                // materials elements, not the enquiry's whole deliverables list.
                $scopeItems = ($elementIds === null && $task->enquiry) ? ($task->enquiry->project_scope ?? []) : [];
                foreach ($scopeItems as $deliverable) {
                    $name = $deliverable['name'] ?? null;
                    if (!$name) continue;

                    $sourceKey = 'enquiry_deliverable_' . md5($name);
                    $existingItem = $logisticsTask->transportItems()
                        ->where('source', $sourceKey)
                        ->first();

                    if (!$existingItem) {
                        $transportItem = $logisticsTask->transportItems()->create([
                            'name'          => $name,
                            'description'   => null,
                            'quantity'      => 1,
                            'unit'          => 'item',
                            'category'      => 'production',
                            'main_category' => 'PRODUCTION',
                            'is_returnable' => true,
                            'source'        => $sourceKey,
                            'created_by'    => auth()->id(),
                        ]);

                        $importedItems[] = [
                            'id'           => $transportItem->id,
                            'name'         => $transportItem->name,
                            'description'  => $transportItem->description,
                            'quantity'     => $transportItem->quantity,
                            'unit'         => $transportItem->unit,
                            'category'     => $transportItem->category,
                            'main_category'=> $transportItem->main_category,
                            'is_returnable'=> $transportItem->is_returnable,
                            'sub_type'     => $transportItem->sub_type,
                            'source'       => $transportItem->source,
                        ];
                    }
                }

                return $importedItems;
            } catch (\Exception $e) {
                \Log::error('Failed to import elements from Materials Task', [
                    'taskId' => $taskId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
        });
    }

    /**
     * Push whole materials elements onto this enquiry's Logistics task's
     * loading sheet, triggered from the Materials task's own element list
     * rather than pulled from the Logistics side. Reuses
     * importProductionElements()'s field mapping and dedup-by-source logic
     * so both entry points stay consistent.
     */
    public function pushMaterialsElementsToLogistics(int $materialsTaskId, array $elementIds): array
    {
        return $this->importProductionElements(
            $this->resolveLogisticsTaskId($materialsTaskId),
            $elementIds
        );
    }

    /**
     * Push individual material line-items ("particulars") onto this
     * enquiry's Logistics task loading sheet — one transport item per
     * material, each with its own real quantity and unit, rather than one
     * item per element with materials summarized into a description.
     */
    public function pushMaterialParticularsToLogistics(int $materialsTaskId, array $materialIds): array
    {
        return $this->importMaterialParticulars(
            $this->resolveLogisticsTaskId($materialsTaskId),
            $materialIds
        );
    }

    /**
     * Resolve the enquiry's Logistics task id from any of its sibling
     * task ids (e.g. the Materials task).
     */
    private function resolveLogisticsTaskId(int $siblingTaskId): int
    {
        $siblingTask = EnquiryTask::findOrFail($siblingTaskId);

        $logisticsTask = EnquiryTask::where('project_enquiry_id', $siblingTask->project_enquiry_id)
            ->where('type', 'logistics')
            ->first();

        if (!$logisticsTask) {
            throw new \Exception("This project's workflow does not include a Logistics task.");
        }

        return $logisticsTask->id;
    }

    /**
     * Import specific material line-items (App\Models\ElementMaterial) from
     * this enquiry's Materials task onto the given Logistics task's loading
     * sheet — one transport item per material. Scoped strictly to the
     * enquiry's own materials data, so a material id from another enquiry is
     * simply excluded, never imported.
     */
    public function importMaterialParticulars(int $taskId, array $materialIds): array
    {
        return DB::transaction(function () use ($taskId, $materialIds) {
            try {
                $task = EnquiryTask::findOrFail($taskId);
                $enquiryId = $task->project_enquiry_id;

                $materialsTask = EnquiryTask::where('project_enquiry_id', $enquiryId)
                    ->where('type', 'materials')
                    ->first();

                if (!$materialsTask) {
                    throw new \Exception('Materials task not found for this enquiry.');
                }

                $materialsData = TaskMaterialsData::where('enquiry_task_id', $materialsTask->id)->first();

                if (!$materialsData) {
                    throw new \Exception('No materials data found. Please complete the Materials Task first.');
                }

                $materials = \App\Models\ElementMaterial::whereIn('id', $materialIds)
                    ->whereHas('element', function ($q) use ($materialsData) {
                        $q->where('task_materials_data_id', $materialsData->id);
                    })
                    ->with(['element', 'libraryMaterial'])
                    ->get();

                if ($materials->isEmpty()) {
                    throw new \Exception('None of the selected materials could be found on this Materials task.');
                }

                $includedMaterials = $materials->filter(
                    fn($material) => $material->is_included && $material->element->is_included
                );

                if ($includedMaterials->isEmpty()) {
                    throw new \Exception('The selected material(s) are excluded from this materials list and cannot be pushed. Include them first.');
                }

                $logisticsTask = LogisticsTask::firstOrCreate(
                    ['task_id' => $taskId],
                    [
                        'project_id' => $this->getProjectIdFromTask($taskId),
                        'created_by' => auth()->id(),
                    ]
                );

                $importedItems = [];

                foreach ($includedMaterials as $material) {
                    $element = $material->element;
                    $name = $material->description ?: ($material->libraryMaterial->name ?? 'Unnamed Material');

                    $mainCategory = $element->category === 'hire' ? 'STORES' : 'PRODUCTION';
                    $sourceKey = 'element_material_' . $material->id;

                    // Loading sheets deal in whole units; a fractional quantity
                    // rounds up rather than down or to nearest, since under-
                    // counting what to load is worse than over-counting.
                    $quantity = max(1, (int) ceil((float) $material->quantity));

                    $payload = [
                        'name' => $name,
                        'description' => 'From: ' . $element->name . ($material->notes ? ' — ' . $material->notes : ''),
                        'quantity' => $quantity,
                        'unit' => $material->unit_of_measurement ?: 'item',
                        'category' => 'production',
                        'main_category' => $mainCategory,
                        'is_returnable' => true,
                        'sub_type' => $element->category === 'hire' ? 'hire' : null,
                        'element_category' => $element->category,
                        'source' => $sourceKey,
                    ];

                    try {
                        $existingItem = $logisticsTask->transportItems()
                            ->where('source', $sourceKey)
                            ->first();

                        if ($existingItem) {
                            $existingItem->update($payload);
                            $transportItem = $existingItem;
                        } else {
                            $transportItem = $logisticsTask->transportItems()->create([
                                ...$payload,
                                'created_by' => auth()->id(),
                            ]);
                        }

                        $importedItems[] = [
                            'id' => $transportItem->id,
                            'name' => $transportItem->name,
                            'description' => $transportItem->description,
                            'quantity' => $transportItem->quantity,
                            'unit' => $transportItem->unit,
                            'category' => $transportItem->category,
                            'main_category' => $transportItem->main_category,
                            'is_returnable' => $transportItem->is_returnable,
                            'sub_type' => $transportItem->sub_type,
                            'element_category' => $transportItem->element_category,
                            'source' => $transportItem->source,
                        ];
                    } catch (\Exception $e) {
                        \Log::error('Failed to create transport item for element material', [
                            'materialId' => $material->id,
                            'error' => $e->getMessage(),
                        ]);
                        throw $e;
                    }
                }

                return $importedItems;
            } catch (\Exception $e) {
                \Log::error('Failed to import material particulars to Logistics', [
                    'taskId' => $taskId,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    /**
     * Get checklist for a task
     */
    public function getChecklistForTask(int $taskId): array
    {
        $logisticsTask = LogisticsTask::where('task_id', $taskId)->first();

        if (!$logisticsTask || !$logisticsTask->checklist) {
            return $this->getEmptyChecklistStructure();
        }

        return $this->formatChecklistData($logisticsTask->checklist->first());
    }

    /**
     * Update checklist data — merges into existing so return_items and other
     * fields not included in partial payloads are never silently wiped.
     */
    public function updateChecklist(int $taskId, array $data): LogisticsChecklist
    {
        return DB::transaction(function () use ($taskId, $data) {
            $logisticsTask = LogisticsTask::firstOrCreate(
                ['task_id' => $taskId],
                [
                    'project_id' => $this->getProjectIdFromTask($taskId),
                    'created_by' => auth()->id(),
                ]
            );

            $checklist = LogisticsChecklist::firstOrNew(
                ['logistics_task_id' => $logisticsTask->id]
            );

            if (!$checklist->exists) {
                $checklist->created_by = auth()->id();
            }

            $existing = is_array($checklist->checklist_data) ? $checklist->checklist_data : [];
            $checklist->checklist_data = array_merge($existing, $data);
            $checklist->updated_by = auth()->id();
            $checklist->save();

            return $checklist->fresh();
        });
    }

    /**
     * Generate checklist from transport items
     */
    public function generateChecklistFromItems(int $taskId): array
    {
        $logisticsTask = LogisticsTask::where('task_id', $taskId)->first();

        if (!$logisticsTask) {
            return $this->getEmptyChecklistStructure();
        }

        $items = $logisticsTask->transportItems->map(function ($item) {
            return [
                'id' => 'item_' . $item->id,
                'item_name' => $item->name,
                'status' => 'missing',
                'notes' => null,
            ];
        })->toArray();

        // Get or create checklist record and merge — preserves any existing
        // return_items / gate states already saved from a previous generate.
        $checklist = LogisticsChecklist::firstOrNew(
            ['logistics_task_id' => $logisticsTask->id],
            ['created_by' => auth()->id()]
        );

        $existing = is_array($checklist->checklist_data) ? $checklist->checklist_data : [];

        $checklistData = array_merge($existing, [
            'items'     => $items,
            'teams'     => $existing['teams']     ?? ['workshop' => false, 'setup' => false, 'setdown' => false],
            'safety'    => $existing['safety']    ?? ['ppe' => false, 'first_aid' => false, 'fire_extinguisher' => false],
            'equipment' => $existing['equipment'] ?? ['tools' => false, 'vehicles' => false, 'communication' => false],
        ]);

        $checklist->checklist_data = $checklistData;
        $checklist->updated_by     = auth()->id();
        $checklist->save();

        return $checklistData;
    }

    /**
     * Generate return checklist items from returnable transport items.
     * Merges into existing checklist_data without wiping existing return progress.
     */
    public function generateReturnChecklistItems(int $taskId): array
    {
        return DB::transaction(function () use ($taskId) {
            $logisticsTask = LogisticsTask::where('task_id', $taskId)
                ->with('transportItems')
                ->firstOrFail();

            $returnableItems = $logisticsTask->transportItems
                ->filter(fn($item) => $item->is_returnable);

            // Get or create the checklist record
            $checklist = LogisticsChecklist::firstOrNew(
                ['logistics_task_id' => $logisticsTask->id],
                ['created_by' => auth()->id()]
            );

            $existing = is_array($checklist->checklist_data) ? $checklist->checklist_data : [];

            // Preserve any already-saved return progress, only add new items
            $existingReturnItems = collect($existing['return_items'] ?? [])
                ->keyBy('id')
                ->toArray();

            $generated = [];
            foreach ($returnableItems as $item) {
                $key = 'return_' . $item->id;
                if (isset($existingReturnItems[$key])) {
                    // Preserve return progress — only update name/qty if manifest changed
                    $existing_entry = $existingReturnItems[$key];
                    $existing_entry['name']                = $item->name;
                    $existing_entry['quantity_dispatched'] = $item->quantity;
                    $existingReturnItems[$key]             = $existing_entry;
                } else {
                    $existingReturnItems[$key] = [
                        'id'                  => $key,
                        'transport_item_id'   => $item->id,
                        'name'                => $item->name,
                        'quantity_dispatched' => $item->quantity,
                        'quantity_returned'   => 0,
                        'unit'                => $item->unit,
                        'main_category'       => $item->main_category,
                        'status'              => 'pending',
                        'condition'           => 'good',
                        'notes'               => null,
                        'returned_at'         => null,
                    ];
                }
                $generated[] = $existingReturnItems[$key];
            }

            // Persist merged return_items back into checklist_data
            $existing['return_items']      = array_values($existingReturnItems);
            $existing['setdown_confirmed'] = $existing['setdown_confirmed'] ?? false;
            $existing['return_authorized'] = $existing['return_authorized'] ?? false;

            $checklist->checklist_data = $existing;
            $checklist->updated_by     = auth()->id();
            $checklist->save();

            return $generated;
        });
    }

    /**
     * Stamp return_authorized = true with timestamp in checklist_data.
     */
    public function authorizeReturn(int $taskId, ?string $notes): array
    {
        return DB::transaction(function () use ($taskId, $notes) {
            $logisticsTask = LogisticsTask::where('task_id', $taskId)->firstOrFail();

            $checklist = LogisticsChecklist::where('logistics_task_id', $logisticsTask->id)
                ->firstOrFail();

            $data = is_array($checklist->checklist_data) ? $checklist->checklist_data : [];

            $data['return_authorized']    = true;
            $data['return_authorized_at'] = now()->toISOString();
            if ($notes) {
                $data['setdown_notes'] = $notes;
            }

            $checklist->checklist_data = $data;
            $checklist->updated_by     = auth()->id();
            $checklist->save();

            return $data;
        });
    }

    /**
     * Get project ID from task ID
     */
    private function getProjectIdFromTask(int $taskId): ?int
    {
        $task = EnquiryTask::find($taskId);
        if (!$task) return null;
        
        $enquiry = $task->enquiry;
        if (!$enquiry) return null;
        
        // Query projects table directly using the correct foreign key 'enquiry_id'
        $project = \App\Models\Project::where('enquiry_id', $enquiry->id)->first();
        if ($project) {
            return $project->id;
        }
        
        return null;
    }

    /**
     * Get empty logistics structure
     */
    private function getEmptyLogisticsStructure(int $taskId): array
    {
        return [
            'task_id' => $taskId,
            'logistics_planning' => [],
            'transport_items' => [],
            'checklist' => $this->getEmptyChecklistStructure(),
            'status' => 'pending',
        ];
    }

    /**
     * Get empty checklist structure
     */
    private function getEmptyChecklistStructure(): array
    {
        return [
            'items'                => [],
            'teams'                => ['workshop' => false, 'setup' => false, 'setdown' => false],
            'safety'               => ['ppe' => false, 'first_aid' => false, 'fire_extinguisher' => false],
            'equipment'            => ['tools' => false, 'vehicles' => false, 'communication' => false],
            'return_items'         => [],
            'setdown_confirmed'    => false,
            'return_authorized'    => false,
            'return_authorized_at' => null,
        ];
    }

    /**
     * Format checklist data for API response
     */
    private function formatChecklistData(?LogisticsChecklist $checklist): array
    {
        $empty = $this->getEmptyChecklistStructure();

        if (!$checklist) {
            return $empty;
        }

        $data = $checklist->checklist_data;

        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($data)) {
            return $empty;
        }

        return [
            'items'                => isset($data['items'])        && is_array($data['items'])        ? $data['items']        : [],
            'teams'                => isset($data['teams'])        && is_array($data['teams'])        ? $data['teams']        : $empty['teams'],
            'safety'               => isset($data['safety'])       && is_array($data['safety'])       ? $data['safety']       : $empty['safety'],
            'equipment'            => isset($data['equipment'])    && is_array($data['equipment'])    ? $data['equipment']    : $empty['equipment'],
            'return_items'         => isset($data['return_items']) && is_array($data['return_items']) ? $data['return_items'] : [],
            'setdown_confirmed'    => $data['setdown_confirmed']    ?? false,
            'return_authorized'    => $data['return_authorized']    ?? false,
            'return_authorized_at' => $data['return_authorized_at'] ?? null,
            'setdown_notes'        => $data['setdown_notes']        ?? null,
        ];
    }
}
