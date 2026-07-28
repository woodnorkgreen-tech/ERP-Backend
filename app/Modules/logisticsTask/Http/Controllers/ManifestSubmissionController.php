<?php

namespace App\Modules\logisticsTask\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\logisticsTask\Models\LogisticsTask;
use App\Modules\logisticsTask\Models\ManifestSubmission;
use App\Modules\logisticsTask\Models\ManifestSubmissionLink;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ManifestSubmissionController extends Controller
{
    private const CATEGORIES = ['PRODUCTION', 'TOOLS_EQUIPMENTS', 'ELECTRICALS', 'STORES'];

    public function index(int $taskId): JsonResponse
    {
        $task = $this->managedTask($taskId);
        $logisticsTask = LogisticsTask::where('task_id', $task->id)->first();

        if (!$logisticsTask) {
            return response()->json(['data' => ['links' => [], 'submissions' => []]]);
        }

        $links = $logisticsTask->manifestLinks()
            ->withCount(['submissions', 'submissions as pending_count' => fn ($query) => $query->where('status', 'pending')])
            ->latest()->get();
        $submissions = ManifestSubmission::query()
            ->whereHas('link', fn ($query) => $query->where('logistics_task_id', $logisticsTask->id))
            ->with('submitter:id,name,email')->latest()->get();

        return response()->json(['data' => ['links' => $links, 'submissions' => $submissions]]);
    }

    public function store(Request $request, int $taskId): JsonResponse
    {
        $task = $this->managedTask($taskId);
        abort_if($task->status === 'completed', 422, 'A submission link cannot be created after dispatch completion.');

        $validated = $request->validate([
            'categories' => 'required|array|min:1',
            'categories.*' => ['required', Rule::in(self::CATEGORIES)],
            'expires_in_days' => 'required|integer|min:1|max:30',
        ]);

        $logisticsTask = LogisticsTask::firstOrCreate(
            ['task_id' => $task->id],
            ['project_id' => $task->enquiry?->project?->id, 'created_by' => auth()->id()]
        );
        $link = $logisticsTask->manifestLinks()->create([
            'token' => (string) Str::uuid(),
            'categories' => array_values(array_unique($validated['categories'])),
            'expires_at' => now()->addDays($validated['expires_in_days']),
            'created_by' => auth()->id(),
        ]);

        return response()->json(['message' => 'Loading sheet submission link created.', 'data' => $link], 201);
    }

    public function revoke(int $taskId, ManifestSubmissionLink $link): JsonResponse
    {
        $task = $this->managedTask($taskId);
        abort_unless($link->logisticsTask?->task_id === $task->id, 404);
        $link->update(['revoked_at' => now()]);

        return response()->json(['message' => 'Loading sheet submission link revoked.']);
    }

    public function show(string $token): JsonResponse
    {
        $link = $this->availableLink($token);
        $task = $link->logisticsTask->task()->with('enquiry:id,title,enquiry_number,job_number,venue,expected_delivery_date')->firstOrFail();

        return response()->json(['data' => [
            'token' => $link->token,
            'categories' => $link->categories,
            'expires_at' => $link->expires_at,
            'project' => [
                'title' => $task->enquiry?->title ?: $task->title,
                'reference' => $task->enquiry?->job_number ?: $task->enquiry?->enquiry_number,
                'venue' => $task->enquiry?->venue,
                'delivery_date' => $task->enquiry?->expected_delivery_date,
            ],
            // Opened without a login (QR code) — this is the link creator,
            // shown as a point of contact, not the (nonexistent) current
            // session user.
            'contact' => $link->creator ? ['name' => $link->creator->name, 'email' => $link->creator->email] : null,
        ]]);
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        $link = $this->availableLink($token);
        abort_if($link->logisticsTask->task?->status === 'completed', 422, 'This dispatch is already complete.');

        $validated = $request->validate([
            'submitted_by_name' => 'required|string|max:255',
            'items' => 'required|array|min:1|max:50',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1|max:100000',
            'items.*.unit' => 'required|string|max:50',
            'items.*.main_category' => ['required', Rule::in($link->categories)],
            'items.*.sub_type' => 'nullable|in:consumable,hire',
            'items.*.description' => 'nullable|string|max:1000',
        ]);

        $created = DB::transaction(function () use ($validated, $link) {
            return collect($validated['items'])->map(function (array $item) use ($link, $validated) {
                $isHire = $item['main_category'] === 'STORES' && ($item['sub_type'] ?? null) === 'hire';
                return $link->submissions()->create([
                    ...$item,
                    'sub_type' => $item['main_category'] === 'STORES' ? ($item['sub_type'] ?? 'consumable') : null,
                    'is_returnable' => in_array($item['main_category'], ['TOOLS_EQUIPMENTS', 'ELECTRICALS'], true) || $isHire,
                    'submitted_by_name' => $validated['submitted_by_name'],
                    'status' => 'pending',
                ]);
            });
        });

        return response()->json(['message' => $created->count().' item(s) sent to Logistics for review.', 'data' => $created], 201);
    }

    public function review(Request $request, int $taskId, ManifestSubmission $submission): JsonResponse
    {
        $task = $this->managedTask($taskId);
        abort_unless($submission->link?->logisticsTask?->task_id === $task->id, 404);
        abort_unless($submission->status === 'pending', 422, 'This submission has already been reviewed.');

        $validated = $request->validate([
            'decision' => 'required|in:accepted,rejected',
            'review_note' => 'nullable|string|max:1000',
            'name' => 'sometimes|required|string|max:255',
            'quantity' => 'sometimes|required|integer|min:1|max:100000',
            'unit' => 'sometimes|required|string|max:50',
            'description' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($submission, $validated) {
            $submission = ManifestSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();
            abort_unless($submission->status === 'pending', 422, 'This submission has already been reviewed.');
            $transportItem = null;
            if ($validated['decision'] === 'accepted') {
                $transportItem = $submission->link->logisticsTask->transportItems()->create([
                    'name' => $validated['name'] ?? $submission->name,
                    'quantity' => $validated['quantity'] ?? $submission->quantity,
                    'unit' => $validated['unit'] ?? $submission->unit,
                    'description' => $validated['description'] ?? $submission->description,
                    'main_category' => $submission->main_category,
                    'category' => $submission->main_category === 'PRODUCTION' ? 'production' : 'custom',
                    'sub_type' => $submission->sub_type,
                    'is_returnable' => $submission->is_returnable,
                    'source' => 'manifest_submission_'.$submission->id,
                    'created_by' => auth()->id(),
                ]);
            }
            $submission->update([
                'status' => $validated['decision'],
                'review_note' => $validated['review_note'] ?? null,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'transport_item_id' => $transportItem?->id,
            ]);
        });

        return response()->json(['message' => $validated['decision'] === 'accepted' ? 'Item added to the loading sheet.' : 'Submission rejected.', 'data' => $submission->fresh('submitter:id,name,email')]);
    }

    private function managedTask(int $taskId): EnquiryTask
    {
        $task = EnquiryTask::with('enquiry.project')->findOrFail($taskId);
        abort_unless($task->type === 'logistics', 404);
        abort_unless($task->isUserAuthorized(auth()->user()), 403, 'You are not authorized to manage this logistics task.');
        return $task;
    }

    private function availableLink(string $token): ManifestSubmissionLink
    {
        $link = ManifestSubmissionLink::with(['logisticsTask.task', 'creator:id,name,email'])->where('token', $token)->firstOrFail();
        abort_unless($link->isAvailable(), 410, 'This loading sheet submission link is no longer available.');
        return $link;
    }
}
