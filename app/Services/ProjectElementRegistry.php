<?php

namespace App\Services;

use App\Constants\ScopeClassification;
use App\Events\MaterialsListChanged;
use App\Models\ElementMaterial;
use App\Models\ProjectElement;
use App\Models\ProjectEnquiry;
use App\Models\TaskMaterialsData;
use App\Models\TaskQuoteData;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** One live enquiry-owned element identity; tasks add specifications and BOMs to it. */
class ProjectElementRegistry
{
    public function syncScope(ProjectEnquiry $enquiry, array $scope): void
    {
        DB::transaction(function () use ($enquiry, $scope) {
            ProjectEnquiry::whereKey($enquiry->id)->lockForUpdate()->firstOrFail();
            $kept = [];
            $changed = false;
            foreach ($scope as $index => $input) {
                $item = $this->scopeItem($input);
                if (!is_string($item['name']) || trim($item['name']) === '' || mb_strlen($item['name']) > 500) {
                    throw ValidationException::withMessages(['project_scope' => 'Every element needs a name of up to 500 characters.']);
                }
                $uuid = $item['uuid'];
                if (in_array($uuid, $kept, true)) {
                    throw ValidationException::withMessages(['project_scope' => 'Each project element must have a distinct ID.']);
                }
                $existing = ProjectElement::withTrashed()->where('uuid', $uuid)->first();
                if ($existing && $existing->enquiry_id != $enquiry->id) {
                    throw ValidationException::withMessages(['project_scope' => 'An element cannot be moved between enquiries.']);
                }
                $element = $existing ?: new ProjectElement(['enquiry_id' => $enquiry->id, 'uuid' => $uuid]);
                $element->fill([
                    'name' => $item['name'], 'classification' => $item['classification'],
                    'status' => $item['status'], 'sort_order' => $index, 'archived_at' => null,
                    ...array_intersect_key($item, array_flip(['required_quantity', 'unit_of_measurement', 'category'])),
                ]);
                $changed = $changed || ! $element->exists || $element->isDirty();
                $element->save();
                $kept[] = $uuid;
            }
            $removed = ProjectElement::where('enquiry_id', $enquiry->id)->whereNotIn('uuid', $kept)->get();
            foreach ($removed as $element) {
                $element->delete();
            }
            $changed = $changed || $removed->isNotEmpty();
            foreach (EnquiryTask::where('project_enquiry_id', $enquiry->id)->where('type', 'materials')->get() as $task) {
                $data = $this->ensureForTask($task->id);
                if ($changed) {
                    $this->invalidateApprovals($data);
                    DB::afterCommit(fn () => MaterialsListChanged::dispatch($task->id));
                }
            }
            if ($changed) {
                ProjectEnquiry::whereKey($enquiry->id)->increment('elements_revision');
            }
            $enquiry->elements_revision = ProjectEnquiry::whereKey($enquiry->id)->value('elements_revision');
            $enquiry->unsetRelation('deliverables');
            $this->syncQuoteDrafts($enquiry);
        });
    }

    public function ensureForTask(int $taskId): TaskMaterialsData
    {
        return DB::transaction(function () use ($taskId) {
            $task = EnquiryTask::findOrFail($taskId);
            if ($task->type !== 'materials') {
                throw ValidationException::withMessages(['task' => 'Project elements can only be specified in a materials task.']);
            }
            ProjectEnquiry::whereKey($task->project_enquiry_id)->lockForUpdate()->firstOrFail();
            $data = TaskMaterialsData::firstOrCreate(['enquiry_task_id' => $taskId], ['project_info' => []]);
            $attached = ProjectElement::where('enquiry_id', $task->project_enquiry_id)->whereNull('task_materials_data_id')
                ->update(['task_materials_data_id' => $data->id]);
            if ($attached) {
                $this->invalidateApprovals($data);
            }

            return $data->fresh(['elements.materials']);
        });
    }

    public function saveMaterials(TaskMaterialsData $data, array $inputs): void
    {
        DB::transaction(function () use ($data, $inputs) {
            $enquiry = ProjectEnquiry::whereKey($data->task->project_enquiry_id)->lockForUpdate()->firstOrFail();
            $kept = [];
            $originalIds = $data->elements()->withTrashed()->pluck('id')->all();
            foreach ($inputs as $index => $input) {
                $element = $this->resolveElement($data, $enquiry, $input, $originalIds);
                if (in_array($element->uuid, $kept, true)) {
                    throw ValidationException::withMessages(['projectElements' => 'The same element was submitted more than once.']);
                }
                $element->fill([
                    'enquiry_id' => $enquiry->id, 'task_materials_data_id' => $data->id,
                    'template_id' => $input['templateId'] ?? $element->template_id,
                    'element_type' => $input['elementType'] ?? $element->element_type ?? 'PRE-DEFINED',
                    'name' => $input['name'] ?? $element->name ?? $input['elementType'] ?? 'Untitled',
                    'classification' => $input['classification'] ?? $element->classification ?? 'PRE-DEFINED',
                    'category' => $input['category'] ?? $element->category ?? 'production',
                    'required_quantity' => $input['requiredQuantity'] ?? $element->required_quantity ?? 1,
                    'unit_of_measurement' => $input['unitOfMeasurement'] ?? $element->unit_of_measurement ?? 'Pcs',
                    'dimensions' => $input['dimensions'] ?? $element->dimensions ?? [],
                    'is_included' => $input['isIncluded'] ?? true,
                    'notes' => array_key_exists('notes', $input) ? $input['notes'] : $element->notes,
                    'source_metadata' => $input['sourceMetadata'] ?? $input['source_metadata'] ?? $element->source_metadata,
                    'sort_order' => $input['sortOrder'] ?? $index, 'archived_at' => null,
                ]);
                $element->save();
                $this->saveLines($element, $input['materials'] ?? []);
                $kept[] = $element->uuid;
            }
            foreach ($data->elements()->whereNotIn('uuid', $kept)->get() as $element) {
                $element->delete();
            }
            ProjectEnquiry::whereKey($enquiry->id)->increment('elements_revision');
            $enquiry->unsetRelation('deliverables');
            $this->syncQuoteDrafts($enquiry);
        });
    }

    private function resolveElement(TaskMaterialsData $data, ProjectEnquiry $enquiry, array $input, array $originalIds): ProjectElement
    {
        $scopeId = $input['scopeId'] ?? $input['scope_id'] ?? null;
        $persistentId = $input['persistent_id'] ?? $input['persistentId'] ?? null;
        $element = null;
        if ($scopeId) {
            $element = ProjectElement::withTrashed()->where('uuid', $scopeId)->first();
        }
        if (! $element && $persistentId) {
            $element = ProjectElement::withTrashed()->where('persistent_id', $persistentId)->first();
        }
        $source = $input['sourceMetadata'] ?? [];
        if (! $element && ($source['source'] ?? null) === 'approved_quote' && ! empty($source['sourceKey'])) {
            $element = ProjectElement::withTrashed()->where('task_materials_data_id', $data->id)
                ->where('source_metadata->sourceKey', $source['sourceKey'])->first();
        }
        if (! $element && ($source['source'] ?? null) !== 'approved_quote' && isset($input['id']) && ctype_digit((string) $input['id']) && in_array((int) $input['id'], $originalIds)) {
            $element = ProjectElement::withTrashed()->where('task_materials_data_id', $data->id)->find($input['id']);
        }
        if ($element && $persistentId && $element->persistent_id !== $persistentId) {
            throw ValidationException::withMessages(['projectElements' => 'The element identity does not match the supplied scope ID.']);
        }
        if ($element && ($element->enquiry_id != $enquiry->id || ($element->task_materials_data_id && $element->task_materials_data_id != $data->id))) {
            throw ValidationException::withMessages(['projectElements' => 'This element belongs to another enquiry or materials task.']);
        }
        if (! $element) {
            $element = new ProjectElement([
                'enquiry_id' => $enquiry->id, 'uuid' => $scopeId ?: (string) Str::uuid(),
                'persistent_id' => $persistentId ?: (string) Str::uuid(),
                'status' => $enquiry->job_number || $enquiry->status === 'quote_approved' ? 'variation' : 'original',
            ]);
        }

        return $element;
    }

    private function saveLines(ProjectElement $element, array $inputs): void
    {
        $kept = [];
        $originalIds = $element->materials()->withTrashed()->pluck('id')->all();
        foreach ($inputs as $index => $input) {
            $pid = $input['persistent_id'] ?? $input['persistentId'] ?? null;
            $line = $pid ? ElementMaterial::withTrashed()->where('persistent_id', $pid)->first() : null;
            if (! $line && isset($input['id']) && ctype_digit((string) $input['id']) && in_array((int) $input['id'], $originalIds)) {
                $line = $element->materials()->withTrashed()->find($input['id']);
            }
            if ($line && $line->project_element_id != $element->id) {
                throw ValidationException::withMessages(['materials' => 'A material line cannot be moved between elements.']);
            }
            $line ??= new ElementMaterial(['project_element_id' => $element->id, 'persistent_id' => $pid ?: (string) Str::uuid()]);
            if ($line->exists && in_array($line->id, $kept, true)) {
                throw ValidationException::withMessages(['materials' => 'The same material line was submitted more than once.']);
            }
            $line->fill([
                'library_material_id' => array_key_exists('libraryMaterialId', $input) ? $input['libraryMaterialId'] : $line->library_material_id,
                'description' => $input['description'], 'unit_of_measurement' => $input['unitOfMeasurement'],
                'quantity' => $input['quantity'],
                'unit_cost' => array_key_exists('unitCost', $input) ? $input['unitCost'] : $line->unit_cost,
                'is_included' => $input['isIncluded'] ?? true, 'is_additional' => $input['isAdditional'] ?? false,
                'notes' => array_key_exists('notes', $input) ? $input['notes'] : $line->notes,
                'source_metadata' => $input['sourceMetadata'] ?? $line->source_metadata,
                'sort_order' => $input['sortOrder'] ?? $index, 'archived_at' => null,
            ]);
            $line->save();
            $kept[] = $line->id;
        }
        $element->materials()->whereNotIn('id', $kept)->delete();
    }

    public function invalidateApprovals(TaskMaterialsData $data): void
    {
        $info = $data->project_info ?? [];
        if (isset($info['approval_status'])) {
            foreach (['project_officer', 'production', 'design'] as $department) {
                $info['approval_status'][$department] = ['approved' => false, 'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'comments' => 'Project elements changed'];
            }
            $info['approval_status']['all_approved'] = false;
            $info['approval_status']['last_approval_at'] = null;
        }
        $data->project_info = $info;
        $data->touch();
    }

    private function scopeItem(mixed $input): array
    {
        if (is_array($input)) {
            if (isset($input['required_quantity']) && (! is_numeric($input['required_quantity']) || $input['required_quantity'] <= 0)) {
                throw ValidationException::withMessages(['project_scope' => 'Element quantity must be greater than zero.']);
            }
            if (isset($input['fulfilment_route']) && ! in_array($input['fulfilment_route'], ['production', 'hire', 'outsourced'], true)) {
                throw ValidationException::withMessages(['project_scope' => 'Invalid fulfilment route.']);
            }

            return [
                'uuid' => $input['uuid'] ?? $input['id'] ?? (string) Str::uuid(),
                'name' => $input['name'] ?? 'Untitled',
                'classification' => strtoupper($input['classification'] ?? 'PRE-DEFINED'),
                'status' => $input['status'] ?? 'original',
                ...array_intersect_key($input, array_flip(['required_quantity', 'unit_of_measurement'])),
                ...(isset($input['fulfilment_route']) ? ['category' => $input['fulfilment_route']] : []),
            ];
        }
        $parts = array_map('trim', explode('|', $input));
        $name = array_shift($parts);
        $classification = 'PRE-DEFINED';
        if (preg_match('/^\[(.*?)\]\s*(.*)$/', $name, $matches)) {
            $classification = strtoupper(trim($matches[1]));
            $name = trim($matches[2]);
        }
        $uuid = (string) Str::uuid();
        $status = 'original';
        foreach ($parts as $part) {
            if (str_starts_with($part, 'id:')) {
                $uuid = trim(substr($part, 3));
            }
            if (str_starts_with($part, 'status:')) {
                $status = trim(substr($part, 7));
            }
        }

        return compact('uuid', 'name', 'classification', 'status');
    }

    public function syncQuoteDrafts(ProjectEnquiry $enquiry): void
    {
        // Keep the legacy JSON projection current without another independent writer.
        ProjectEnquiry::whereKey($enquiry->id)->update(['project_scope' => json_encode($enquiry->getProjectScopeAttribute())]);
        $scope = ProjectElement::where('enquiry_id', $enquiry->id)->get()->keyBy('uuid');
        foreach (EnquiryTask::where('project_enquiry_id', $enquiry->id)->where('type', 'quote')->get() as $task) {
            $quote = TaskQuoteData::firstOrCreate(['enquiry_task_id' => $task->id]);
            $seen = [];
            $elements = [];
            foreach (($quote->materials ?? []) as $element) {
                $id = $element['scopeId'] ?? null;
                if ($id && ! $scope->has($id)) {
                    continue;
                }
                if ($id) {
                    $element['name'] = $scope[$id]->name;
                    $element['category'] = $scope[$id]->classification;
                    $seen[$id] = true;
                }
                $elements[] = $element;
            }
            foreach ($scope as $id => $element) {
                if (isset($seen[$id])) {
                    continue;
                }
                $elements[] = [
                    'id' => (string) Str::uuid(), 'scopeId' => $id, 'name' => $element->name,
                    'category' => $element->classification, 'description' => '',
                    'quantity' => (float) ($element->required_quantity ?? 1), 'baseTotal' => 0,
                    'marginAmount' => 0, 'marginPercentage' => ScopeClassification::defaultMargin($element->classification),
                    'finalTotal' => 0, 'templateId' => ScopeClassification::toTemplateId($element->classification),
                    'materials' => [], 'isIncluded' => true,
                ];
            }
            // Only the editable working copy changes. QuoteVersion / QuoteApproval snapshots are untouched.
            $quote->update(['materials' => $elements]);
        }
    }
}
