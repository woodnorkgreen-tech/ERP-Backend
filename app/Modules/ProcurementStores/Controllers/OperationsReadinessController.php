<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\ProcurementStores\Services\OperationsReadinessService;
use Illuminate\Http\Request;

class OperationsReadinessController extends Controller
{
    public function show(Request $request, OperationsReadinessService $readiness)
    {
        abort_unless($request->user()?->can(Permissions::MATERIALS_LIBRARY_VIEW)
            || $request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        return response()->json(['data' => $readiness->report()]);
    }
}
