<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Governance\DocumentNumberingRegister;
use App\Modules\Finance\Governance\FinanceConfigVersion;
use App\Modules\Finance\Governance\GovernanceCatalogue;
use App\Modules\Finance\Governance\GovernanceCentre;
use App\Modules\Finance\Governance\GovernanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Finance Setup & Controls (Report 75).
 *
 * Every change to how Finance is configured goes through here: prepared,
 * submitted, reviewed, approved, dated, activated. No endpoint writes a setting
 * directly, and none can be used to approve one's own accounting proposal.
 *
 * Reading needs `finance.config.view`. What a caller may DO is decided per
 * action in GovernanceService, by permissions checked directly, so that being a
 * Super Admin does not make someone the accountant.
 */
class FinanceGovernanceController extends Controller
{
    public function __construct(private GovernanceCentre $centre, private GovernanceService $governance, private GovernanceCatalogue $catalogue)
    {
    }

    /** Every action starts from what the database holds now, not from an earlier request. */
    public function callAction($method, $parameters)
    {
        $this->catalogue->forget();

        return parent::callAction($method, $parameters);
    }

    private function viewer(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_CONFIG_VIEW), 403, 'You are not authorised to view Finance configuration.');
    }

    public function overview(Request $request): JsonResponse
    {
        $this->viewer($request);

        return response()->json(['data' => $this->centre->overview($request->user())]);
    }

    public function items(Request $request): JsonResponse
    {
        $this->viewer($request);
        $validated = $request->validate(['domain' => ['nullable', 'string', 'in:'.implode(',', array_keys(GovernanceCatalogue::DOMAINS))]]);

        return response()->json(['data' => $this->centre->listing($validated['domain'] ?? null, $request->user()),
            'domains' => GovernanceCatalogue::DOMAINS]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $this->viewer($request);
        $item = $this->centre->item($key, $request->user()) ?? abort(404, 'That configuration item does not exist.');

        return response()->json(['data' => $item]);
    }

    /** Accounts that may be chosen for an item. Only compatible ones are returned. */
    public function accounts(Request $request, string $key): JsonResponse
    {
        $this->viewer($request);
        $item = $this->catalogue->item($key) ?? abort(404);
        abort_unless(in_array($item['type'], ['account', 'bank', 'mpesa'], true), 404);
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:80']]);

        return response()->json(['data' => $this->catalogue->eligibleAccounts($item, $validated['q'] ?? null)]);
    }

    public function numbering(Request $request): JsonResponse
    {
        $this->viewer($request);

        return response()->json(['data' => DocumentNumberingRegister::report()]);
    }

    public function history(Request $request): JsonResponse
    {
        $this->viewer($request);
        $validated = $request->validate(['domain' => ['nullable', 'string'], 'key' => ['nullable', 'string', 'max:120'], 'limit' => ['nullable', 'integer', 'min:1', 'max:500']]);

        return response()->json(['data' => $this->centre->history($validated['key'] ?? null, $validated['limit'] ?? 150, $validated['domain'] ?? null)]);
    }

    // ---- Preparing -----------------------------------------------------------

    private function proposalInput(Request $request): array
    {
        return $request->validate([
            'value' => ['present', 'array'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
        ]);
    }

    public function propose(Request $request, string $key): JsonResponse
    {
        $input = $this->proposalInput($request);
        // One step for the person, one transaction for the database: a submission that
        // is refused leaves no half-made draft behind to block the next attempt.
        $version = \DB::transaction(function () use ($request, $key, $input) {
            $version = $this->governance->draft($key, $request->user(), $input['value'], $input['reason'] ?? null, $input['effective_from'] ?? null);

            return $request->boolean('submit') ? $this->governance->submit($version, $request->user(), $version->revision) : $version;
        });

        return $this->answer($request, $version, $request->boolean('submit') ? 'Submitted for approval.' : 'Draft saved. Nothing changes until it is approved and activated.', 201);
    }

    /** Super Admin: approve and apply one setting in a single step. */
    public function applyNow(Request $request, string $key): JsonResponse
    {
        $input = $request->validate(['value' => ['nullable', 'array'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $version = $this->governance->applyNow($key, $request->user(), $input['value'] ?? null, $input['reason'] ?? null);

        return $this->answer($request, $version, 'Approved and applied. It is in force'.($version->in_force_from?->isFuture() ? ' from '.$version->in_force_from->toFormattedDateString() : ' now').'.');
    }

    /** Super Admin: approve and apply every setting that already has an answer. */
    public function applyAll(Request $request): JsonResponse
    {
        $input = $request->validate(['keys' => ['nullable', 'array', 'max:500'], 'keys.*' => ['string', 'max:120']]);
        $result = $this->governance->applyAll($request->user(), $input['keys'] ?? null);
        $this->catalogue->forget();
        $applied = count($result['applied']);

        return response()->json([
            'message' => $applied.' setting'.($applied === 1 ? '' : 's').' approved and applied.'
                .(count($result['needs_answer']) ? ' '.count($result['needs_answer']).' still need your answer.' : '')
                .(count($result['skipped']) ? ' '.count($result['skipped']).' could not be applied; the reason is shown for each.' : ''),
            'data' => $result,
        ]);
    }

    /**
     * Put several suggestions forward for review in one step. Each becomes its own
     * proposal, submitted by the person who clicked: this approves nothing.
     */
    public function proposeSuggestions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keys' => ['required', 'array', 'min:1', 'max:200'], 'keys.*' => ['string', 'max:120'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'], 'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);
        $submitted = \DB::transaction(function () use ($validated, $request) {
            $done = [];
            foreach (array_unique($validated['keys']) as $key) {
                $item = $this->catalogue->item($key) ?? abort(404, "{$key} does not exist.");
                if ($item['suggestion'] === null) {
                    abort(422, "{$item['title']} has no suggested answer to put forward. It needs its own proposal.");
                }
                $version = $this->governance->draft($key, $request->user(), $item['suggestion'], $validated['reason'], $validated['effective_from']);
                $done[] = $this->governance->submit($version, $request->user(), $version->revision)->item_key;
            }

            return $done;
        });

        return response()->json(['message' => count($submitted).' suggestion(s) submitted for approval. None of them is approved.', 'data' => ['submitted' => $submitted]], 201);
    }

    public function update(Request $request, FinanceConfigVersion $version): JsonResponse
    {
        $input = $this->proposalInput($request);
        $version = $this->governance->update($version, $request->user(), $this->revision($request), $input['value'], $input['reason'] ?? null, $input['effective_from'] ?? null);

        return $this->answer($request, $version, 'Draft updated.');
    }

    public function act(Request $request, FinanceConfigVersion $version, string $action): JsonResponse
    {
        $comment = $request->validate(['comment' => ['nullable', 'string', 'max:2000']])['comment'] ?? null;
        $user = $request->user();
        $revision = $this->revision($request);

        [$version, $message] = match ($action) {
            'submit' => [$this->governance->submit($version, $user, $revision), 'Submitted for approval.'],
            'withdraw' => [$this->governance->withdraw($version, $user, $revision, $comment), 'Proposal withdrawn. It is kept in the history.'],
            'review' => [$this->governance->startReview($version, $user, $revision), 'Marked as under review.'],
            'return' => [$this->governance->returnForCorrection($version, $user, $revision, (string) $comment), 'Returned for correction.'],
            'reject' => [$this->governance->reject($version, $user, $revision, (string) $comment), 'Rejected. Nothing has changed.'],
            'approve' => [$this->governance->approve($version, $user, $revision, $comment), 'Approved. It takes effect only once it is activated.'],
            'activate' => [$this->governance->activate($version, $user, $revision), 'Activated.'],
            default => abort(404),
        };

        return $this->answer($request, $version, $message);
    }

    /** Approve exactly the proposals named, each at the revision the approver was shown. */
    public function approveMany(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'selection' => ['required', 'array', 'min:1', 'max:200'],
            'selection.*.id' => ['required', 'integer'], 'selection.*.revision' => ['required', 'integer', 'min:1'],
            'comment' => ['nullable', 'string', 'max:2000'],
            // The count the approver confirmed on screen.
            'confirmed_count' => ['required', 'integer'],
        ]);
        if ((int) $validated['confirmed_count'] !== count($validated['selection'])) {
            // The list changed between what was shown and what was sent.
            abort(422, 'The number confirmed does not match the items selected. Review the list again.');
        }
        $approved = $this->governance->approveMany($validated['selection'], $request->user(), $validated['comment'] ?? null);

        return response()->json(['message' => count($approved).' proposal(s) approved. Each still needs activating.',
            'data' => ['approved' => array_map(fn ($v) => $v->item_key, $approved)]]);
    }

    /** Activate exactly the approved proposals named. Approval is still required for each. */
    public function activateMany(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'selection' => ['required', 'array', 'min:1', 'max:200'],
            'selection.*.id' => ['required', 'integer'], 'selection.*.revision' => ['required', 'integer', 'min:1'],
            'confirmed_count' => ['required', 'integer'],
        ]);
        if ((int) $validated['confirmed_count'] !== count($validated['selection'])) {
            abort(422, 'The number confirmed does not match the items selected. Review the list again.');
        }
        $activated = $this->governance->activateMany($validated['selection'], $request->user());

        return response()->json(['message' => count($activated).' proposal(s) activated.',
            'data' => ['activated' => array_map(fn ($v) => $v->item_key, $activated)]]);
    }

    private function revision(Request $request): int
    {
        return (int) $request->validate(['revision' => ['required', 'integer', 'min:1']])['revision'];
    }

    private function answer(Request $request, FinanceConfigVersion $version, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => $this->centre->item($version->item_key, $request->user())], $status);
    }
}
