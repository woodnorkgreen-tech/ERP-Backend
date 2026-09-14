<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\FinanceWorkQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceWorkQueueController extends Controller
{
    public function count(Request $request, FinanceWorkQueueService $queue): JsonResponse
    {
        return response()->json(['data' => $queue->countsForUser($request->user())]);
    }

    public function index(Request $request, FinanceWorkQueueService $queue): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'work_type' => ['nullable', 'string', 'max:64'],
            'priority' => ['nullable', 'in:normal,watch,overdue,exception'],
            'assignment' => ['nullable', 'in:all,mine,unassigned'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
        $filters['user_id'] = $request->user()->id;

        return response()->json(['data' => $queue->forUser($request->user(), $filters)]);
    }

    public function claim(Request $request, string $workType, int $sourceId, FinanceWorkQueueService $queue): JsonResponse
    {
        return response()->json(['data' => $queue->claim($request->user(), $workType, $sourceId)]);
    }

    public function release(Request $request, string $workType, int $sourceId, FinanceWorkQueueService $queue): JsonResponse
    {
        $queue->release($request->user(), $workType, $sourceId);

        return response()->json(['status' => 'success']);
    }

    public function reassign(Request $request, string $workType, int $sourceId, FinanceWorkQueueService $queue): JsonResponse
    {
        $data = $request->validate([
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $queue->reassign(
            $request->user(), $workType, $sourceId, (int) $data['assigned_to'], $data['note'] ?? null,
        )]);
    }

    public function history(Request $request, string $workType, int $sourceId, FinanceWorkQueueService $queue): JsonResponse
    {
        return response()->json(['data' => $queue->history($request->user(), $workType, $sourceId)]);
    }
}
