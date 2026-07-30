<?php

namespace App\Modules\logisticsTask\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\logisticsTask\Models\LogisticsChecklist;
use App\Modules\logisticsTask\Models\LogisticsTask;
use App\Modules\logisticsTask\Models\ReturnConfirmationLink;
use App\Modules\logisticsTask\Services\LogisticsTaskService;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReturnConfirmationController extends Controller
{
    public function __construct(private LogisticsTaskService $logisticsService) {}

    public function index(int $taskId): JsonResponse
    {
        $task = $this->managedTask($taskId);
        $logisticsTask = LogisticsTask::where('task_id', $task->id)->first();
        $links = $logisticsTask?->returnConfirmationLinks()->with('confirmer:id,name')->latest()->get() ?? collect();
        return response()->json(['data' => $links]);
    }

    public function store(Request $request, int $taskId): JsonResponse
    {
        $task = $this->managedTask($taskId);
        $validated = $request->validate(['expires_in_hours' => 'required|integer|min:1|max:168']);
        $logisticsTask = LogisticsTask::where('task_id', $task->id)->firstOrFail();
        abort_if(($this->logisticsService->getChecklistForTask($task->id)['return_authorized'] ?? false), 422, 'Returns have already been authorized.');
        $items = $this->logisticsService->generateReturnChecklistItems($task->id);
        abort_if(empty($items), 422, 'There are no returnable items on this loading sheet.');

        $logisticsTask->returnConfirmationLinks()->whereNull('revoked_at')->whereNull('confirmed_at')->update(['revoked_at' => now()]);
        $link = $logisticsTask->returnConfirmationLinks()->create([
            'token' => (string) Str::uuid(), 'expires_at' => now()->addHours($validated['expires_in_hours']), 'created_by' => auth()->id(),
        ]);
        return response()->json(['message' => 'Return confirmation link created.', 'data' => $link], 201);
    }

    public function revoke(int $taskId, ReturnConfirmationLink $link): JsonResponse
    {
        $task = $this->managedTask($taskId);
        abort_unless($link->logisticsTask?->task_id === $task->id, 404);
        abort_if($link->confirmed_at, 422, 'A confirmed return record cannot be revoked.');
        $link->update(['revoked_at' => now()]);
        return response()->json(['message' => 'Return confirmation link revoked.']);
    }

    public function show(string $token): JsonResponse
    {
        $link = $this->availableLink($token);
        $task = $link->logisticsTask->task()->with('enquiry:id,title,enquiry_number,job_number,venue')->firstOrFail();
        $data = $this->logisticsService->getChecklistForTask($task->id);
        return response()->json(['data' => [
            'expires_at' => $link->expires_at, 'confirmed_at' => $link->confirmed_at, 'confirmed_by' => $link->confirmed_by_name ?? $link->confirmer?->name,
            'project' => ['title' => $task->enquiry?->title ?: $task->title, 'reference' => $task->enquiry?->job_number ?: $task->enquiry?->enquiry_number, 'venue' => $task->enquiry?->venue],
            // Opened without a login (QR code) — "officer" is whoever created
            // the link, shown as a point of contact, not the current session.
            'officer' => $link->creator ? ['name' => $link->creator->name, 'email' => $link->creator->email] : null,
            'items' => $data['return_items'] ?? [],
        ]]);
    }

    public function updateItems(Request $request, string $token): JsonResponse
    {
        $link = $this->availableLink($token);
        abort_if($link->confirmed_at, 422, 'Returns have already been confirmed.');
        $validated = $request->validate([
            'checked_by' => 'required|string|max:255',
            'items' => 'required|array|min:1', 'items.*.id' => 'required|string',
            'items.*.quantity_returned' => 'required|integer|min:0',
            'items.*.condition' => 'required|in:good,worn,damaged', 'items.*.notes' => 'nullable|string|max:500',
        ]);

        $items = DB::transaction(function () use ($link, $validated) {
            $checklist = LogisticsChecklist::where('logistics_task_id', $link->logistics_task_id)->lockForUpdate()->firstOrFail();
            $data = $checklist->checklist_data ?? [];
            $updates = collect($validated['items'])->keyBy('id');
            $known = collect($data['return_items'] ?? [])->keyBy('id');
            abort_if($updates->keys()->diff($known->keys())->isNotEmpty(), 422, 'The return checklist has changed. Refresh this page.');
            $data['return_items'] = $known->map(function ($item) use ($updates, $validated) {
                $update = $updates->get($item['id']); if (!$update) return $item;
                abort_if($update['quantity_returned'] > $item['quantity_dispatched'], 422, "Returned quantity for {$item['name']} exceeds dispatched quantity.");
                $qty = $update['quantity_returned']; $condition = $update['condition'];
                $status = $qty === 0 ? 'missing' : ($condition === 'damaged' ? 'damaged' : ($qty < $item['quantity_dispatched'] ? 'partial' : 'returned'));
                return array_merge($item, $update, ['status' => $status, 'returned_at' => $qty > 0 ? now()->toIso8601String() : null, 'checkedBy' => $validated['checked_by'], 'checkedAt' => now()->toIso8601String()]);
            })->values()->all();
            $checklist->update(['checklist_data' => $data]);
            return $data['return_items'];
        });
        return response()->json(['message' => 'Return progress saved.', 'data' => $items]);
    }

    public function confirm(Request $request, string $token): JsonResponse
    {
        $link = $this->availableLink($token);
        abort_if($link->confirmed_at, 422, 'Returns have already been confirmed.');
        $validated = $request->validate(['confirmed_by_name' => 'required|string|max:255']);
        $items = $this->logisticsService->getChecklistForTask($link->logisticsTask->task_id)['return_items'] ?? [];
        abort_if(empty($items), 422, 'There are no return items.');
        abort_if(collect($items)->contains(fn ($item) => ($item['status'] ?? 'pending') === 'pending'), 422, 'Account for every return item before confirmation.');
        abort_if(collect($items)->contains(fn ($item) => ($item['status'] ?? null) !== 'returned' && blank($item['notes'] ?? null)), 422, 'Add a note for every missing, partial or damaged item.');
        $link->update(['confirmed_by_name' => $validated['confirmed_by_name'], 'confirmed_at' => now()]);
        return response()->json(['message' => 'Returns confirmed and sent for Logistics review.', 'data' => $link->fresh()]);
    }

    private function managedTask(int $taskId): EnquiryTask
    {
        $task = EnquiryTask::with('enquiry.project')->findOrFail($taskId); abort_unless($task->type === 'logistics', 404);
        abort_unless($task->isUserAuthorized(auth()->user()), 403, 'You are not authorized to manage this logistics task.'); return $task;
    }
    private function availableLink(string $token): ReturnConfirmationLink
    {
        $link = ReturnConfirmationLink::with(['logisticsTask.task', 'confirmer:id,name', 'creator:id,name,email'])->where('token', $token)->firstOrFail();
        abort_unless($link->isAvailable(), 410, 'This return confirmation link is no longer available.'); return $link;
    }
}
