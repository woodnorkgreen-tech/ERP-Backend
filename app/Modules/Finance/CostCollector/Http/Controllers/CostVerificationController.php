<?php

namespace App\Modules\Finance\CostCollector\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Finance\CostCollector\Exceptions\CostValidationException;
use App\Modules\Finance\CostCollector\Http\Resources\CostLineResource;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\CostQueueQuery;
use App\Modules\Finance\CostCollector\Services\CostVerificationService;
use App\Modules\Finance\CostCollector\Services\CostTaxPricer;
use App\Modules\HR\Models\HRAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class CostVerificationController extends Controller
{
    public function __construct(
        private CostVerificationService $verification,
        private CostQueueQuery $queue,
        private CostTaxPricer $pricer,
    ) {}

    /**
     * The queue.
     *
     * Ordered oldest first by default, because the risk in cost verification is
     * a backlog ageing quietly, not a single large item.
     *
     * The summary block is computed over the same filters as the rows, so the
     * header and the table can never describe different sets — previously
     * `awaiting` counted the whole system regardless of what was on screen.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CostLine::class);

        $filters = $this->queueFilters($request);

        $lines = $this->queue->build($filters)->paginate($filters['per_page']);

        return response()->json([
            'data' => CostLineResource::collection($lines->items()),
            'meta' => [
                'current_page' => $lines->currentPage(),
                'last_page' => $lines->lastPage(),
                'per_page' => $lines->perPage(),
                'total' => $lines->total(),
                'from' => $lines->firstItem(),
                'to' => $lines->lastItem(),
                // Kept under its old name so nothing that reads it breaks; it
                // now means "matching this filter" rather than "in the system".
                'awaiting' => $lines->total(),
                'summary' => $this->queue->summary($filters),
                'filters' => $filters,
            ],
        ]);
    }

    /**
     * One cost, with everything behind it.
     *
     * Every read in this module was a list, so there was no way to see a single
     * line's full history — earlier revisions and query responses were exposed
     * only as `latest_*` and everything before them was unreachable.
     */
    public function show(CostLine $cost): JsonResponse
    {
        $this->authorize('viewAny', CostLine::class);

        $line = CostLine::withReferenceNames()
            ->with(['expenseCode', 'submittedBy', 'verifiedBy', 'vatTreatment', 'whtCategory'])
            ->findOrFail($cost->id);

        return response()->json([
            'data' => new CostLineResource($line),
            'history' => $this->history($line),
        ]);
    }

    /**
     * What Finance may still choose at verification, and what it would cost.
     *
     * The API has accepted `vat_treatment_id` and `wht_category_id` since the
     * module shipped and the UI sent neither, so withholding was priced
     * server-side and committed without anyone seeing it. This returns the
     * options in force on the cost's own date — the tables are effective-dated,
     * so today's rate is the wrong list for an August receipt — together with
     * the resulting split and the journal legs it will post.
     */
    public function taxPreview(Request $request, CostLine $cost): JsonResponse
    {
        $this->authorize('verify', $cost);

        $validated = $request->validate([
            'tax_amount' => 'nullable|numeric|min:0',
            'vat_treatment_id' => 'nullable|integer|exists:vat_treatments,id',
            'wht_category_id' => 'nullable|integer|exists:wht_categories,id',
        ]);

        return response()->json(
            $this->pricer->preview($cost, array_filter($validated, fn ($v) => $v !== null)),
        );
    }

    /**
     * The filters the queue understands.
     *
     * Booleans come off the query string as "true"/"false", which Laravel's
     * `boolean` rule rejects, so presence is tested and the value read through
     * `boolean()`. Absent stays absent — it must mean "do not narrow", which is
     * a different instruction from `false`.
     *
     * @return array<string, mixed>
     */
    private function queueFilters(Request $request): array
    {
        $validated = $request->validate([
            'status' => 'nullable|string|max:32',
            'job_number' => 'nullable|string|max:64',
            'enquiry_id' => 'nullable|integer',
            'expense_code' => 'nullable|string|max:24',
            'expense_family' => 'nullable|string|max:120',
            'cost_centre_id' => 'nullable|integer',
            'submitted_by' => 'nullable|integer',
            'currency' => 'nullable|string|size:3',
            'origin' => 'nullable|in:captured,petty_cash,procurement,stores',
            'age_bucket' => 'nullable|in:current,watch,late',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'q' => 'nullable|string|max:120',
            'sort' => 'nullable|in:age,incurred_at,amount,ref,submitted',
            'direction' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $filters = array_filter($validated, fn ($value) => $value !== null && $value !== '');

        if ($request->boolean('unbudgeted')) {
            $filters['unbudgeted'] = true;
        }

        if ($request->has('has_evidence')) {
            $filters['has_evidence'] = $request->boolean('has_evidence');
        }

        $filters['per_page'] = $validated['per_page'] ?? 25;

        return $filters;
    }

    /**
     * The line's own record of what happened to it, newest last.
     *
     * Assembled from three places because that is where it lives: the capture
     * meta holds revisions and query answers, the columns hold the verification
     * stamps, and self-verification overrides go to the shared audit log.
     *
     * @return array<int, array<string, mixed>>
     */
    private function history(CostLine $line): array
    {
        $events = collect();

        $events->push([
            'at' => $line->created_at?->toIso8601String(),
            'event' => 'captured',
            'by' => $line->submitted_by_name ?: $line->submittedBy?->name,
            'note' => $line->source_type ? 'Posted by ' . class_basename($line->source_type) : null,
        ]);

        // The names come from the writers: `correctQueried` stamps revised_by,
        // `resubmit` stamps responded_by, `reclassify` stamps reclassified_by.
        // Ids are resolved to names in one lookup rather than per event.
        $names = $this->namesFor($line);

        foreach ($line->capture_meta['revisions'] ?? [] as $revision) {
            $events->push([
                'at' => $revision['revised_at'] ?? null,
                'event' => 'corrected',
                'by' => $names[$revision['revised_by'] ?? null] ?? null,
                'note' => $revision['response'] ?? null,
                'before' => $revision['before'] ?? null,
                'after' => $revision['after'] ?? null,
            ]);
        }

        foreach ($line->capture_meta['query_responses'] ?? [] as $response) {
            $events->push([
                'at' => $response['responded_at'] ?? null,
                'event' => 'answered',
                'by' => $names[$response['responded_by'] ?? null] ?? null,
                'note' => $response['response'] ?? null,
            ]);
        }

        foreach ($line->capture_meta['reclassifications'] ?? [] as $change) {
            $events->push([
                'at' => $change['at'] ?? null,
                'event' => 'reclassified',
                'by' => $names[$change['by'] ?? null] ?? null,
                'note' => $change['reason'] ?? null,
                'before' => ['consumes_line_id' => $change['from'] ?? null],
                'after' => ['consumes_line_id' => $change['to'] ?? null],
            ]);
        }

        if ($line->verified_at) {
            $events->push([
                'at' => $line->verified_at->toIso8601String(),
                'event' => $line->status === CostLine::STATUS_REJECTED ? 'rejected' : 'verified',
                'by' => $line->verifiedBy?->name,
                'note' => $line->query_note,
            ]);
        }

        foreach (HRAuditLog::where('model_type', CostLine::class)->where('model_id', $line->id)
            ->orderBy('id')->get() as $log) {
            $events->push([
                'at' => $log->created_at?->toIso8601String(),
                'event' => 'override',
                'by' => $log->user?->name,
                'note' => $log->context['reason'] ?? $log->message,
            ]);
        }

        return $events->sortBy('at')->values()->all();
    }

    /**
     * Every user id mentioned anywhere in this line's meta, resolved once.
     *
     * @return array<int, string>
     */
    private function namesFor(CostLine $line): array
    {
        $meta = $line->capture_meta ?? [];

        $ids = collect([
            ...collect($meta['revisions'] ?? [])->pluck('revised_by'),
            ...collect($meta['query_responses'] ?? [])->pluck('responded_by'),
            ...collect($meta['reclassifications'] ?? [])->pluck('by'),
        ])->filter()->unique();

        return $ids->isEmpty()
            ? []
            : User::whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * Point unbudgeted spend at the budget line it actually belongs to.
     *
     * The module's headline signal is the unbudgeted chip, and it had no
     * resolution path: `consumes_line_id` could only be set at capture, so a
     * verifier who knew which line a cost belonged to could do nothing but
     * query it back to the reporter and hope they picked correctly. Costs
     * therefore stayed permanently unbudgeted and the panel that lists them
     * only ever grew.
     */
    public function reclassify(Request $request, CostLine $cost): JsonResponse
    {
        $this->authorize('verify', $cost);

        $validated = $request->validate([
            // Null is a legitimate instruction: a cost wrongly attached to a
            // budget line has to be detachable, or a mis-code is permanent.
            'consumes_line_id' => 'nullable|integer|exists:cost_lines,id',
            'reason' => 'required|string|min:10|max:500',
        ]);

        return $this->attempt(fn () => $this->verification->reclassify(
            $cost,
            $request->user(),
            $validated['consumes_line_id'] ?? null,
            $validated['reason'],
        ), 'Cost re-pointed at its budget line.');
    }

    public function verify(Request $request, CostLine $cost): JsonResponse
    {
        $this->authorize('verify', $cost);

        $validated = $request->validate([
            'tax_amount' => 'nullable|numeric|min:0',
            'vat_treatment_id' => 'nullable|integer|exists:vat_treatments,id',
            'wht_category_id' => 'nullable|integer|exists:wht_categories,id',
            // Only meaningful when verifying your own cost. The service decides
            // whether it is needed and whether this person may use it at all;
            // shape-checking it here keeps a one-word "reason" out of the audit.
            'override_reason' => 'nullable|string|min:15|max:1000',
        ]);

        $tax = array_filter(
            Arr::except($validated, ['override_reason']),
            fn ($value) => $value !== null,
        );

        return $this->attempt(fn () => $this->verification->verify(
            $cost, $request->user(), $tax, $validated['override_reason'] ?? null,
        ), 'Cost verified.');
    }

    public function query(Request $request, CostLine $cost): JsonResponse
    {
        $this->authorize('verify', $cost);

        $validated = $request->validate(['note' => 'required|string|max:500']);

        return $this->attempt(
            fn () => $this->verification->query($cost, $request->user(), $validated['note']),
            'Query sent back to the person who reported it.',
        );
    }

    public function reject(Request $request, CostLine $cost): JsonResponse
    {
        $this->authorize('verify', $cost);

        $validated = $request->validate(['reason' => 'required|string|max:500']);

        return $this->attempt(
            fn () => $this->verification->reject($cost, $request->user(), $validated['reason']),
            'Cost rejected.',
        );
    }

    public function reverse(Request $request, CostLine $cost): JsonResponse
    {
        $this->authorize('reverse', $cost);

        $validated = $request->validate(['reason' => 'required|string|max:500']);

        return $this->attempt(
            fn () => $this->verification->reverse($cost, $request->user(), $validated['reason']),
            'Cost reversed. It no longer counts against the project.',
        );
    }

    public function resubmit(Request $request, CostLine $cost): JsonResponse
    {
        $this->authorize('resubmit', $cost);

        $validated = $request->validate([
            'response' => 'required|string|min:3|max:500',
        ]);

        return $this->attempt(
            fn () => $this->verification->resubmit($cost, $request->user(), $validated['response']),
            'Response recorded and sent back for verification.',
        );
    }

    /** @param callable():CostLine $action */
    private function attempt(callable $action, string $message): JsonResponse
    {
        try {
            $line = $action();
        } catch (CostValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], 422);
        }

        // Reloaded through the same scope the queue uses so the row the client
        // swaps in carries the payee, coding and journal reference it was
        // already displaying — a thinner payload here would blank those cells.
        $fresh = CostLine::withReferenceNames()
            ->with(['expenseCode', 'submittedBy', 'verifiedBy', 'vatTreatment', 'whtCategory'])
            ->find($line->id);

        return response()->json([
            'message' => $message,
            'data' => new CostLineResource($fresh ?? $line),
        ]);
    }
}
