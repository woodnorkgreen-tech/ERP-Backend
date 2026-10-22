<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\FinanceEventPosting;
use App\Modules\Finance\Services\FinanceEventPoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cost postings that did not reach the books, and the way to send them again.
 *
 * The visible half of FinanceEventPoster (Report 76A): a posting that failed,
 * or that a dying request left unfinished, is listed here with its error, and
 * a person who may verify costs can retry it. Retrying never repeats the
 * business event — the order stays approved, the payment stays paid — it only
 * runs the idempotent posting again.
 */
class FinanceEventPostingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_REPORTS_VIEW), 403);

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:attention,failed,pending,posted,all'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'attention';

        $page = FinanceEventPosting::query()
            ->with(['requestedBy:id,name', 'lastRetriedBy:id,name'])
            ->when($status === 'attention', fn ($q) => $q->needingAttention())
            ->when(in_array($status, ['failed', 'pending', 'posted'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('updated_at')
            ->paginate($filters['per_page'] ?? 25);

        $canRetry = (bool) $request->user()->can(Permissions::FINANCE_COSTS_VERIFY);

        return response()->json([
            'status' => 'success',
            'data' => collect($page->items())->map(fn (FinanceEventPosting $posting) => $this->present($posting, $canRetry)),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'needing_attention' => FinanceEventPosting::query()->needingAttention()->count(),
                'can_retry' => $canRetry,
            ],
        ]);
    }

    public function retry(Request $request, FinanceEventPosting $posting, FinanceEventPoster $poster): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_COSTS_VERIFY), 403);

        if (! $posting->needsAttention()) {
            return response()->json([
                'status' => 'success',
                'message' => $posting->status === FinanceEventPosting::STATUS_POSTED
                    ? 'This posting has already reached the books. Nothing was posted again.'
                    : 'This posting is still in progress and does not need to be sent again.',
                'data' => $this->present($posting, true),
            ]);
        }

        $posting = $poster->retry($posting, (int) $request->user()->id);

        if ($posting->status !== FinanceEventPosting::STATUS_POSTED) {
            return response()->json([
                'status' => 'error',
                'message' => 'The posting still could not be completed: '.$posting->last_error,
                'data' => $this->present($posting, true),
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Posted. The source document was not changed or repeated.',
            'data' => $this->present($posting, true),
        ]);
    }

    private function present(FinanceEventPosting $posting, bool $canRetry): array
    {
        return [
            'id' => $posting->id,
            'posting_type' => $posting->posting_type,
            'label' => FinanceEventPoster::LABELS[$posting->posting_type] ?? $posting->posting_type,
            'subject_id' => $posting->subject_id,
            'status' => $posting->status,
            'stale' => $posting->isStale(),
            'needs_attention' => $posting->needsAttention(),
            'outcome' => $posting->outcome,
            'attempts' => $posting->attempts,
            'last_error' => $posting->last_error,
            'requested_by' => $posting->requestedBy?->name,
            'requested_at' => $posting->requested_at?->toIso8601String(),
            'last_attempt_at' => $posting->last_attempt_at?->toIso8601String(),
            'posted_at' => $posting->posted_at?->toIso8601String(),
            'last_retried_by' => $posting->lastRetriedBy?->name,
            'can_retry' => $canRetry && $posting->needsAttention(),
        ];
    }
}
