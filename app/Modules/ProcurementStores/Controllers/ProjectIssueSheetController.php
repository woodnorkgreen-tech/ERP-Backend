<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Models\ElementMaterial;
use App\Models\Project;
use App\Modules\ProcurementStores\Services\ProjectIssueSheet;
use App\Modules\ProcurementStores\Services\ProjectMaterialResolution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Report 79E — the Project Materials Desk's one read, and its bulk match.
 */
class ProjectIssueSheetController extends Controller
{
    public function show(Project $project, ProjectIssueSheet $sheet): JsonResponse
    {
        $user = auth()->user();
        if (! $user?->can(Permissions::STORES_VIEW) && ! $user?->can(Permissions::STORES_MANAGE)) {
            return response()->json(['message' => 'You are not permitted to view Stores issuing.'], 403);
        }

        return response()->json(['data' => $sheet->build($project->loadMissing('enquiry:id,title'))]);
    }

    /**
     * Confirm several suggested matches at once. Each line is linked in its
     * own transaction by the same rule as Resolve, so one that fails (someone
     * linked it a moment ago, or its unit cannot be read) reports why and the
     * others still link. Nothing here moves stock or money, which is why
     * partial success is the right answer.
     */
    public function resolveBatch(Request $request, ProjectMaterialResolution $resolution): JsonResponse
    {
        if (! auth()->user()?->can(Permissions::STORES_MANAGE)) {
            return response()->json(['message' => 'Only Stores team members can resolve a material to the catalogue.'], 403);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1|max:200',
            'items.*.project_material_id' => 'required|integer|distinct|exists:element_materials,id',
            'items.*.material_id' => 'required|integer|exists:library_materials,id',
        ]);

        $results = [];
        foreach ($validated['items'] as $item) {
            $line = ElementMaterial::findOrFail($item['project_material_id']);
            try {
                $result = $resolution->resolve($line, (int) $item['material_id'], (int) auth()->id(), 'Confirmed on the issue sheet.');
                $results[] = [
                    'project_material_id' => $line->id, 'ok' => true, 'outcome' => $result['outcome'],
                    'material' => ['id' => $result['material']->id, 'name' => $result['material']->material_name],
                ];
            } catch (ValidationException $e) {
                $results[] = ['project_material_id' => $line->id, 'ok' => false, 'message' => collect($e->errors())->flatten()->first()];
            } catch (\DomainException $e) {
                $results[] = ['project_material_id' => $line->id, 'ok' => false, 'message' => $e->getMessage()];
            }
        }

        $linked = count(array_filter($results, fn ($r) => $r['ok']));
        $failed = count($results) - $linked;

        return response()->json([
            'message' => trim("{$linked} line".($linked === 1 ? '' : 's').' linked.'
                .($failed ? " {$failed} could not be linked — see each line." : '').' Nothing was issued, ordered or charged.'),
            'results' => $results,
            'linked' => $linked,
            'failed' => $failed,
        ]);
    }
}
