<?php

namespace App\Modules\Printing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\Department;
use App\Modules\Printing\Models\PrintManualConsumption;
use App\Modules\Printing\Resources\PrintManualConsumptionResource;
use App\Modules\Printing\Services\PrintMaterialUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PrintManualConsumptionController extends Controller
{
    public function __construct(private readonly PrintMaterialUsageService $usage)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $items = PrintManualConsumption::query()
            ->with('roll')
            ->when($request->filled('reason'), fn ($q) => $q->where('reason', $request->string('reason')))
            ->when($request->filled('project_enquiry_id'), fn ($q) => $q->where('project_enquiry_id', $request->integer('project_enquiry_id')))
            ->latest('consumed_at')
            ->paginate((int) $request->get('per_page', 20));

        return response()->json($items->through(fn ($item) => new PrintManualConsumptionResource($item)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'print_roll_id' => ['required', 'integer', 'exists:print_rolls,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'project_enquiry_id' => ['nullable', 'integer', 'exists:project_enquiries,id'],
            'operator_id' => ['nullable', 'integer', $this->designOperatorRule()],
            'reason' => ['required', 'string', 'max:100'],
            'quantity_m' => ['required', 'numeric', 'min:0.001'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'consumed_at' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => new PrintManualConsumptionResource($this->usage->manualConsumption($data))], 201);
    }

    private function designOperatorRule()
    {
        $departmentIds = Department::query()
            ->where('name', 'like', '%design%')
            ->orWhere('name', 'like', '%creative%')
            ->pluck('id');

        return Rule::exists('users', 'id')
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->whereIn('department_id', $departmentIds));
    }
}
