<?php

namespace App\Modules\Printing\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Project;
use App\Modules\Assets\Models\Asset;
use App\Modules\HR\Models\Department;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrintLookupController extends Controller
{
    public function projects(Request $request): JsonResponse
    {
        $term = trim((string) $request->get('search', ''));
        $projects = Project::query()
            ->with('enquiry.client')
            ->when($term !== '', function ($query) use ($term) {
                $like = "%{$term}%";
                $query->where(function ($inner) use ($like) {
                    $inner->where('project_id', 'like', $like)
                        ->orWhereHas('enquiry', fn ($enquiry) => $enquiry
                            ->where('job_number', 'like', $like)
                            ->orWhere('title', 'like', $like)
                            ->orWhereHas('client', fn ($client) => $client->where('full_name', 'like', $like)));
                });
            })
            ->latest('id')
            ->limit((int) $request->get('limit', 15))
            ->get()
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'project_code' => $project->project_id,
                'job_number' => $project->enquiry?->job_number,
                'project_name' => $project->enquiry?->title,
                'client_name' => $project->enquiry?->client?->full_name,
            ]);

        return response()->json(['data' => $projects]);
    }

    public function materials(Request $request): JsonResponse
    {
        $materials = LibraryMaterial::query()
            ->active()
            ->when($request->filled('search'), fn ($q) => $q->search($request->get('search')))
            ->orderBy('material_name')
            ->limit((int) $request->get('limit', 12))
            ->get()
            ->map(fn ($material) => [
                'id' => $material->id,
                'material_code' => $material->material_code,
                'material_name' => $material->material_name,
                'unit_of_measure' => $material->unit_of_measure,
            ]);

        return response()->json(['data' => $materials]);
    }

    public function machines(Request $request): JsonResponse
    {
        $machines = Asset::query()
            ->with(['assetCategory:id,name', 'department:id,name'])
            ->active()
            ->where(function ($q) {
                $q->where('name', 'like', '%print%')
                    ->orWhere('category', 'like', '%print%')
                    ->orWhere('subcategory', 'like', '%print%')
                    ->orWhereHas('assetCategory', fn ($category) => $category->where('name', 'like', '%print%'))
                    ->orWhereHas('department', fn ($department) => $department->where('name', 'like', '%print%'));
            })
            ->when($request->filled('search'), fn ($q) => $q->search($request->get('search')))
            ->orderBy('name')
            ->limit((int) $request->get('limit', 12))
            ->get()
            ->map(fn ($asset) => [
                'id' => $asset->id,
                'name' => $asset->name,
                'asset_code' => $asset->asset_code,
                'category' => $asset->assetCategory?->name ?? $asset->category,
                'department' => $asset->department?->name,
            ]);

        return response()->json(['data' => $machines]);
    }

    public function operators(Request $request): JsonResponse
    {
        $designDepartmentIds = Department::query()
            ->where('name', 'like', '%design%')
            ->orWhere('name', 'like', '%creative%')
            ->pluck('id');

        $operators = User::query()
            ->with('department:id,name')
            ->active()
            ->whereIn('department_id', $designDepartmentIds)
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->get('search') . '%';
                $q->where(fn ($inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->orderBy('name')
            ->limit((int) $request->get('limit', 12))
            ->get()
            ->map(fn ($operator) => [
                'id' => $operator->id,
                'name' => $operator->name,
                'email' => $operator->email,
                'department' => $operator->department?->name,
            ]);

        return response()->json(['data' => $operators]);
    }
}
