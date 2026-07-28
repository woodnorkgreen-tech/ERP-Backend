<?php

namespace App\Modules\logisticsTask\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\logisticsTask\Models\LoadingConfirmationLink;
use App\Modules\logisticsTask\Models\LogisticsChecklist;
use App\Modules\logisticsTask\Models\LogisticsTask;
use App\Modules\logisticsTask\Services\LogisticsTaskService;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoadingConfirmationController extends Controller
{
    public function __construct(private LogisticsTaskService $logisticsService) {}

    public function index(int $taskId): JsonResponse
    {
        $task = $this->managedTask($taskId);
        $logisticsTask = LogisticsTask::where('task_id', $task->id)->first();
        $links = $logisticsTask?->loadingConfirmationLinks()->with('confirmer:id,name')->latest()->get() ?? collect();

        return response()->json(['data' => $links]);
    }

    public function store(Request $request, int $taskId): JsonResponse
    {
        $task = $this->managedTask($taskId);
        abort_if($task->status === 'completed', 422, 'A confirmation link cannot be created after dispatch completion.');
        $validated = $request->validate(['expires_in_hours' => 'required|integer|min:1|max:168']);
        $logisticsTask = LogisticsTask::firstOrCreate(
            ['task_id' => $task->id],
            ['project_id' => $task->enquiry?->project?->id, 'created_by' => auth()->id()]
        );

        if ($logisticsTask->transportItems()->doesntExist()) {
            abort(422, 'Add items to the loading sheet before creating a confirmation link.');
        }
        $this->logisticsService->generateChecklistFromItems($task->id);

        $logisticsTask->loadingConfirmationLinks()->whereNull('revoked_at')->whereNull('confirmed_at')->update(['revoked_at' => now()]);
        $link = $logisticsTask->loadingConfirmationLinks()->create([
            'token' => (string) Str::uuid(),
            'expires_at' => now()->addHours($validated['expires_in_hours']),
            'created_by' => auth()->id(),
        ]);

        return response()->json(['message' => 'Loading confirmation link created.', 'data' => $link], 201);
    }

    public function revoke(int $taskId, LoadingConfirmationLink $link): JsonResponse
    {
        $task = $this->managedTask($taskId);
        abort_unless($link->logisticsTask?->task_id === $task->id, 404);
        abort_if($link->confirmed_at, 422, 'A confirmed loading record cannot be revoked.');
        $link->update(['revoked_at' => now()]);
        return response()->json(['message' => 'Loading confirmation link revoked.']);
    }

    public function show(string $token): JsonResponse
    {
        $link = $this->availableLink($token);
        $task = $link->logisticsTask->task()->with('enquiry:id,title,enquiry_number,job_number,venue,expected_delivery_date')->firstOrFail();
        // Always reconcile the phone checklist with the current loading sheet.
        // Existing progress is preserved by the service while new/edited rows
        // receive their latest quantity, unit and category metadata.
        $this->logisticsService->generateChecklistFromItems($task->id);
        $checklist = $this->logisticsService->getChecklistForTask($task->id);

        return response()->json(['data' => [
            'token' => $link->token,
            'expires_at' => $link->expires_at,
            'confirmed_at' => $link->confirmed_at,
            'confirmed_by' => $link->confirmer?->name,
            'project' => [
                'title' => $task->enquiry?->title ?: $task->title,
                'reference' => $task->enquiry?->job_number ?: $task->enquiry?->enquiry_number,
                'venue' => $task->enquiry?->venue,
                'delivery_date' => $task->enquiry?->expected_delivery_date,
            ],
            'officer' => ['name' => auth()->user()->name, 'email' => auth()->user()->email],
            'items' => $checklist['items'] ?? [],
        ]]);
    }

    public function updateItems(Request $request, string $token): JsonResponse
    {
        $link = $this->availableLink($token);
        abort_if($link->confirmed_at, 422, 'Loading has already been confirmed.');
        abort_if($link->logisticsTask->task?->status === 'completed', 422, 'This dispatch is already complete.');
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|string',
            'items.*.status' => 'required|in:present,missing,coming_later',
            'items.*.notes' => 'nullable|string|max:500',
        ]);

        $items = DB::transaction(function () use ($link, $validated) {
            $checklist = LogisticsChecklist::where('logistics_task_id', $link->logistics_task_id)->lockForUpdate()->firstOrFail();
            $data = $checklist->checklist_data ?? [];
            $updates = collect($validated['items'])->keyBy('id');
            $knownIds = collect($data['items'] ?? [])->pluck('id');
            abort_if($updates->keys()->diff($knownIds)->isNotEmpty(), 422, 'The loading sheet has changed. Refresh this page.');
            $data['items'] = collect($data['items'] ?? [])->map(function ($item) use ($updates) {
                $update = $updates->get($item['id']);
                if (!$update) return $item;
                return array_merge($item, [
                    'status' => $update['status'], 'notes' => $update['notes'] ?? null,
                    'checkedBy' => auth()->user()->name, 'checkedAt' => now()->toIso8601String(),
                ]);
            })->values()->all();
            $checklist->update(['checklist_data' => $data, 'updated_by' => auth()->id()]);
            return $data['items'];
        });

        return response()->json(['message' => 'Loading progress saved.', 'data' => $items]);
    }

    public function confirm(string $token): JsonResponse
    {
        $link = $this->availableLink($token);
        abort_if($link->confirmed_at, 422, 'Loading has already been confirmed.');
        $items = $this->logisticsService->getChecklistForTask($link->logisticsTask->task_id)['items'] ?? [];
        abort_if(empty($items), 422, 'The loading sheet has no items.');
        abort_if(collect($items)->contains(fn ($item) => ($item['status'] ?? null) !== 'present'), 422, 'Every item must be marked Loaded before final confirmation.');
        $link->update(['confirmed_by' => auth()->id(), 'confirmed_at' => now()]);

        return response()->json(['message' => 'Loading confirmed successfully.', 'data' => $link->fresh('confirmer:id,name')]);
    }

    private function managedTask(int $taskId): EnquiryTask
    {
        $task = EnquiryTask::with('enquiry.project')->findOrFail($taskId);
        abort_unless($task->type === 'logistics', 404);
        abort_unless($task->isUserAuthorized(auth()->user()), 403, 'You are not authorized to manage this logistics task.');
        return $task;
    }

    private function availableLink(string $token): LoadingConfirmationLink
    {
        $link = LoadingConfirmationLink::with(['logisticsTask.task', 'confirmer:id,name'])->where('token', $token)->firstOrFail();
        abort_unless($link->isAvailable(), 410, 'This loading confirmation link is no longer available.');
        return $link;
    }
}
