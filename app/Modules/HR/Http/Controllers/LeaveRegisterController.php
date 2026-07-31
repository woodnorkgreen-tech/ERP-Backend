<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Exports\LeaveRegisterExport;
use App\Modules\HR\Services\LeaveManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class LeaveRegisterController extends Controller
{
    public function __construct(private readonly LeaveManagementService $leaveService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);
        $perPage = max(1, min($request->integer('per_page', 20), 100));
        $page = max(1, $request->integer('page', 1));

        $paginator = $this->leaveService->getLeaveRegister($request->user(), $year, $perPage, $page);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'year' => $year,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function export(Request $request)
    {
        $year = $request->integer('year', now()->year);
        $entries = $this->leaveService->getLeaveRegister($request->user(), $year);
        $filename = "Leave_Register_{$year}_" . now()->format('Y-m-d_His') . '.xlsx';

        return Excel::download(new LeaveRegisterExport($entries), $filename);
    }
}
