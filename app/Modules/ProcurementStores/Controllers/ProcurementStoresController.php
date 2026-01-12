<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ProcurementStoresController extends Controller
{
    /**
     * Diagnostic test endpoint.
     */
    public function test(): JsonResponse
    {
        return response()->json([
            'message' => 'Procurement and Stores Module is active',
            'status' => 'success'
        ]);
    }
}
