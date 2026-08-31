<?php

namespace App\Modules\Finance\CostCollector\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Evidence upload.
 *
 * Separated from cost submission on purpose: a receipt is photographed on a
 * phone, often on a poor connection, and one failed upload should not lose the
 * rest of the form. The client uploads first, then submits the cost referencing
 * the returned paths.
 */
class CostEvidenceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // `key` ties the upload to a requirement declared by the expense
            // code's minimum_evidence, so the collector can tell an eTIMS invoice
            // from a delivery note rather than counting attachments.
            'key' => 'required|string|max:64',
            'file' => 'required|file|max:10240|mimes:jpg,jpeg,png,heic,webp,pdf',
        ]);

        $path = $request->file('file')->store(
            'cost-evidence/' . $request->user()->id . '/' . now()->format('Y/m'),
            'public',
        );

        return response()->json([
            'data' => [
                'key' => $validated['key'],
                'path' => $path,
                'url' => asset('storage/' . $path),
                'uploaded_at' => now()->toIso8601String(),
            ],
        ], 201);
    }
}
