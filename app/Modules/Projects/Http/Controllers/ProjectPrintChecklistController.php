<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Models\ProjectEnquiry;
use App\Modules\Projects\Models\EnquiryTask;
use App\Modules\Projects\Models\ProjectPrintChecklist;
use App\Modules\Projects\Models\ProjectPrintChecklistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectPrintChecklistController
{
    public function show(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        $checklist = ProjectPrintChecklist::query()
            ->with('items')
            ->where('project_enquiry_id', $enquiry->id)
            ->first();

        $designTask = $this->designTask($enquiry);
        $sourceDeliverables = $this->sourceDeliverables($enquiry);
        $projectOfficerName = $enquiry->projectOfficer?->name ?: $enquiry->assignedPo?->name ?: '';
        $designerName = $designTask->assignedTo?->name ?: $designTask->assignedUsers->first()?->name ?: '';
        $items = $checklist
            ? $this->attachSourceValues($checklist->items->map(fn (ProjectPrintChecklistItem $item) => $item->toArray()), $sourceDeliverables)
            : collect();

        return response()->json(['data' => [
            'project_name' => $enquiry->title,
            'project_officer_name' => $projectOfficerName ?: ($checklist?->project_officer_name ?? ''),
            'designer_name' => $designerName ?: ($checklist?->designer_name ?? ''),
            'date_prepared' => $checklist?->date_prepared?->format('Y-m-d') ?? '',
            'items' => $items ?? [],
            'artwork_options' => $this->artworkOptions($sourceDeliverables),
            'editable_fields' => [
                'project_officer_name' => $projectOfficerName === '',
                'designer_name' => $designerName === '',
            ],
            'can_edit' => $this->canEdit($designTask, $enquiry, $request),
        ]]);
    }

    public function update(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        $designTask = $this->designTask($enquiry);
        abort_unless($this->canEdit($designTask, $enquiry, $request), 403, 'You are not authorized to edit this project checklist.');

        $validated = $request->validate([
            'project_officer_name' => ['nullable', 'string', 'max:255'],
            'designer_name' => ['nullable', 'string', 'max:255'],
            'date_prepared' => ['nullable', 'date'],
            'items' => ['required', 'array', 'max:500'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.item_artwork' => ['nullable', 'string', 'max:2000', 'distinct:ignore_case'],
            'items.*.print_length' => ['nullable', 'string', 'max:100'],
            'items.*.print_width' => ['nullable', 'string', 'max:100'],
            'items.*.print_height' => ['nullable', 'string', 'max:100'],
            'items.*.material' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'string', 'max:100'],
            'items.*.structure_surface' => ['nullable', 'string', 'max:1000'],
            'items.*.bleed_allowance' => ['nullable', 'string', 'max:1000'],
            'items.*.client_approval_status' => ['nullable', 'string', 'max:255'],
            'items.*.final_artwork_check' => ['nullable', 'string', 'max:1000'],
            'items.*.printing_status' => ['nullable', 'string', 'max:255'],
            'items.*.print_technician' => ['nullable', 'string', 'max:255'],
        ]);

        $sourceDeliverables = $this->sourceDeliverables($enquiry);
        $projectOfficerName = $enquiry->projectOfficer?->name ?: $enquiry->assignedPo?->name ?: '';
        $designerName = $designTask->assignedTo?->name ?: $designTask->assignedUsers->first()?->name ?: '';

        $checklist = DB::transaction(function () use ($validated, $enquiry, $request, $sourceDeliverables, $projectOfficerName, $designerName) {
            $checklist = ProjectPrintChecklist::query()->firstOrNew(['project_enquiry_id' => $enquiry->id]);
            if (!$checklist->exists) {
                $checklist->created_by = $request->user()->id;
            }
            $checklist->fill([
                'project_officer_name' => $projectOfficerName === '' ? ($validated['project_officer_name'] ?? null) : null,
                'designer_name' => $designerName === '' ? ($validated['designer_name'] ?? null) : null,
                'date_prepared' => $validated['date_prepared'] ?? null,
            ]);
            $checklist->save();

            $keptIds = [];
            $sourceOccurrences = [];
            foreach ($validated['items'] as $index => $input) {
                $sourceName = $input['item_artwork'] ?? '';
                if (!empty($input['id'])) {
                    $item = $checklist->items()->whereKey($input['id'])->firstOrFail();
                } else {
                    $item = new ProjectPrintChecklistItem();
                    $checklist->items()->save($item);
                }

                $sourceValues = $this->sourceValuesForName($sourceName, $sourceDeliverables, $sourceOccurrences);
                foreach ($sourceValues as $field => $value) {
                    // Keep project-owned values authoritative even if a client
                    // submits a modified value for a field disabled in the UI.
                    $input[$field] = $value;
                }

                $item->fill([
                    'item_artwork' => $input['item_artwork'] ?? null,
                    'print_length' => $input['print_length'] ?? null,
                    'print_width' => $input['print_width'] ?? null,
                    'print_height' => $input['print_height'] ?? null,
                    'material' => $input['material'] ?? null,
                    'quantity' => $input['quantity'] ?? null,
                    'structure_surface' => $input['structure_surface'] ?? null,
                    'bleed_allowance' => $input['bleed_allowance'] ?? null,
                    'client_approval_status' => $input['client_approval_status'] ?? null,
                    'final_artwork_check' => $input['final_artwork_check'] ?? null,
                    'printing_status' => $input['printing_status'] ?? null,
                    'print_technician' => $input['print_technician'] ?? null,
                    'sort_order' => $index,
                ]);
                $item->save();
                $keptIds[] = $item->id;
            }

            $checklist->items()->when(
                $keptIds,
                fn ($query) => $query->whereNotIn('id', $keptIds),
                fn ($query) => $query,
            )->delete();

            return $checklist->fresh('items');
        });

        $designTask = $this->designTask($enquiry);
        $projectOfficerName = $enquiry->projectOfficer?->name ?: $enquiry->assignedPo?->name ?: '';
        $designerName = $designTask->assignedTo?->name ?: $designTask->assignedUsers->first()?->name ?: '';
        $items = $this->attachSourceValues($checklist->items->map(fn (ProjectPrintChecklistItem $item) => $item->toArray()), $sourceDeliverables);

        return response()->json(['data' => [
            'project_name' => $enquiry->title,
            'project_officer_name' => $projectOfficerName ?: ($checklist->project_officer_name ?? ''),
            'designer_name' => $designerName ?: ($checklist->designer_name ?? ''),
            'date_prepared' => $checklist->date_prepared?->format('Y-m-d') ?? '',
            'items' => $items,
            'artwork_options' => $this->artworkOptions($sourceDeliverables),
            'editable_fields' => [
                'project_officer_name' => $projectOfficerName === '',
                'designer_name' => $designerName === '',
            ],
            'can_edit' => $this->canEdit($designTask, $enquiry, $request),
        ], 'message' => 'Printing checklist saved.']);
    }

    private function designTask(ProjectEnquiry $enquiry): EnquiryTask
    {
        return EnquiryTask::query()
            ->with(['assignedTo', 'assignedUsers'])
            ->where('project_enquiry_id', $enquiry->id)
            ->where('type', 'design')
            ->firstOrFail();
    }

    private function canEdit(EnquiryTask $designTask, ProjectEnquiry $enquiry, Request $request): bool
    {
        return $designTask->isUserAuthorized($request->user())
            && !in_array($enquiry->status, ['completed', 'closed', 'cancelled'], true);
    }

    private function sourceDeliverables(ProjectEnquiry $enquiry)
    {
        return $enquiry->deliverables()
            ->whereNull('archived_at')
            ->with(['materials' => fn ($query) => $query->where('is_included', true)->orderBy('sort_order')])
            ->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    private function artworkOptions($sourceDeliverables): array
    {
        return $sourceDeliverables->map(fn ($deliverable) => [
            'name' => trim((string) $deliverable->name),
            ...$this->dimensionValues($deliverable->dimensions),
            'material' => $deliverable->materials->pluck('description')->filter()->unique()->implode(', '),
            'quantity' => $this->formatQuantity($deliverable->required_quantity),
        ])->filter(fn (array $option) => $option['name'] !== '')->values()->all();
    }

    private function attachSourceValues($items, $sourceDeliverables)
    {
        $occurrences = [];

        return $items->map(function (array $item) use ($sourceDeliverables, &$occurrences) {
            $item['source_values'] = $this->sourceValuesForName(
                $item['item_artwork'] ?? '',
                $sourceDeliverables,
                $occurrences,
            );

            foreach ($item['source_values'] as $field => $value) {
                $item[$field] = $value;
            }

            foreach (['print_length', 'print_width', 'print_height'] as $field) {
                if (empty($item[$field]) && !empty($item['print_measurement'])) {
                    $item[$field] = $this->legacyMeasurementValues($item['print_measurement'])[$field] ?? '';
                }
            }

            return $item;
        })->values();
    }

    private function sourceValuesForName(string $name, $sourceDeliverables, array &$occurrences): array
    {
        $key = mb_strtolower(trim($name));
        if ($key === '') {
            return [];
        }

        $matches = $sourceDeliverables->filter(fn ($deliverable) => mb_strtolower(trim((string) $deliverable->name)) === $key)->values();
        if ($matches->isEmpty()) {
            return [];
        }

        $index = $occurrences[$key] ?? 0;
        $occurrences[$key] = $index + 1;
        $deliverable = $matches->get($index) ?? $matches->last();

        return array_filter([
            'item_artwork' => trim((string) $deliverable->name),
            ...$this->dimensionValues($deliverable->dimensions),
            'material' => $deliverable->materials->pluck('description')->filter()->unique()->implode(', '),
            'quantity' => $this->formatQuantity($deliverable->required_quantity),
        ], fn ($value) => $value !== '');
    }

    private function dimensionValues(?array $dimensions): array
    {
        if (!is_array($dimensions)) {
            return [];
        }

        return collect(['length', 'width', 'height'])
            ->mapWithKeys(function (string $key) use ($dimensions) {
                $value = $dimensions[$key] ?? null;
                if (!is_numeric($value) || (float) $value <= 0) {
                    return [];
                }

                // Project dimensions are stored in millimetres; the checklist uses metres.
                return ['print_'.$key => rtrim(rtrim(number_format((float) $value / 1000, 3, '.', ''), '0'), '.')];
            })
            ->all();
    }

    private function legacyMeasurementValues(string $measurement): array
    {
        $measurement = preg_replace('/\s*m\s*$/i', '', trim($measurement)) ?? '';
        $parts = preg_split('/\s*[x×]\s*/u', $measurement) ?: [];

        return collect(['length', 'width', 'height'])
            ->mapWithKeys(fn (string $key, int $index) => isset($parts[$index]) && is_numeric(trim($parts[$index]))
                ? ['print_'.$key => trim($parts[$index])]
                : [])
            ->all();
    }

    private function formatQuantity(mixed $quantity): string
    {
        if (!is_numeric($quantity)) {
            return '';
        }

        return rtrim(rtrim(number_format((float) $quantity, 4, '.', ''), '0'), '.');
    }
}
