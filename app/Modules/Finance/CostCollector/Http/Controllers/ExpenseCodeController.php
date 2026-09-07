<?php

namespace App\Modules\Finance\CostCollector\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\CostCollector\Http\Resources\ExpenseCodeResource;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Constants\Permissions;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The expense-type picker.
 *
 * The catalogue runs to hundreds of codes, so a flat dropdown is unusable. This
 * exposes search and family drill-down instead, and returns the per-code form
 * definition so the client renders the right fields without knowing anything
 * about any particular expense type.
 */
class ExpenseCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'nullable|string|max:120',
            'family' => 'nullable|string|max:120',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $query = ExpenseCode::active();

        if ($search = $validated['q'] ?? null) {
            $query->where(function ($q) use ($search) {
                $q->where('expense_type', 'like', "%{$search}%")
                    ->orWhere('simple_meaning', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($family = $validated['family'] ?? null) {
            $query->where('expense_family', $family);
        }

        $this->applyJobContext($request, $query);
        $this->applyProcurable($request, $query);

        $codes = $query->orderBy('sort_order')->orderBy('expense_type')
            ->limit($validated['limit'] ?? 50)
            ->get();

        return response()->json(['data' => ExpenseCodeResource::collection($codes)]);
    }

    /**
     * The expense types this person actually uses.
     *
     * The catalogue runs to hundreds of codes, but any one person works from a
     * handful — a site supervisor reports meals, fuel and casual labour, and
     * little else. Searching for the same three codes every day is the single
     * largest source of avoidable interaction on the capture screen, so the
     * client can render these as one-tap chips and skip the search entirely.
     *
     * Ordered by most recently used rather than most frequently: what someone
     * reported this morning is a better predictor of what they are reporting now
     * than what they reported most often last quarter.
     */
    public function recent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:12',
        ]);

        $ranked = CostLine::query()
            ->where('submitted_by_user_id', $request->user()->id)
            ->whereNotNull('expense_code_id')
            ->groupBy('expense_code_id')
            ->orderByRaw('MAX(id) DESC')
            ->limit($validated['limit'] ?? 6)
            ->pluck('expense_code_id');

        if ($ranked->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $query = ExpenseCode::active()->whereIn('id', $ranked);

        $this->applyJobContext($request, $query);
        $this->applyProcurable($request, $query);

        // Re-sorted in PHP: whereIn returns rows in whatever order the index
        // yields, which would discard the recency ranking the query just built.
        $codes = $query->get()
            ->sortBy(fn (ExpenseCode $code) => $ranked->search($code->id))
            ->values();

        return response()->json(['data' => ExpenseCodeResource::collection($codes)]);
    }

    /**
     * The families, for browsing the catalogue instead of recalling a term.
     *
     * Ordered by how many codes each holds rather than alphabetically. The
     * distribution is heavily lopsided — direct materials carries most of the
     * catalogue while the balance-sheet families hold one or two apiece — so
     * size is a good proxy for how likely a family is to be what someone is
     * reaching for, and it puts the spending families above the accountant-only
     * ones without hiding anything.
     *
     * `accounting_class` rides along so the client can label a family that is
     * plainly not a purchase (WIP close-out, Input VAT) as what it is.
     */
    public function families(Request $request): JsonResponse
    {
        $query = ExpenseCode::active()
            // MAX() rather than grouping on the class as well. A family no longer
            // maps to exactly one class — "Premises and utilities" holds workshop
            // electricity (production overhead) beside office rent (operating
            // expense), because the requester is buying the same kind of thing
            // either way. Grouping by both would split that into two tiles named
            // after the ledger, which is the failure this catalogue is moving
            // away from; so one tile wins, and the class it reports is indicative.
            ->selectRaw('expense_family, MAX(accounting_class) as accounting_class, COUNT(*) as code_count')
            ->groupBy('expense_family')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('expense_family');

        $this->applyJobContext($request, $query);
        $this->applyProcurable($request, $query);

        return response()->json(['data' => $query->get()]);
    }

    /**
     * Narrow the catalogue to the codes this context may legitimately use.
     *
     * With a job in hand the rule is a simple complement: everything except the
     * codes that forbid a job number.
     *
     * Without one it is not the mirror image, and treating it as `= not_allowed`
     * was the bug. `optional` and `conditional` codes say in their own rule that
     * a job number is not required, so they belong in the no-job list too.
     * Excluding them left an office requisition with seven choices — four of
     * which are not purchases at all — and hid the two codes those screens most
     * need: NE-008 "Material bought for store", the classification for a stores
     * replenishment, and OE-FIN-001 "Bank and mobile-money transaction charges",
     * which a non-project petty-cash spend could therefore never be coded to.
     *
     * Read through boolean() rather than validated as `boolean`: a query string
     * carries this as the text "true"/"false", and Laravel's boolean rule
     * accepts only true/false/1/0/"1"/"0" — so every search from the capture
     * form was answered with a 422 and the picker stayed empty. has() still
     * separates "not filtering" from "filtering on false".
     */
    /**
     * Narrow to what a purchase order can actually carry.
     *
     * Asked for by the procurement requisition and nothing else. The catalogue
     * has to hold codes that are not purchases — a petty-cash float top-up, a
     * stores issue, VAT remitted — because the cost collector posts all of them,
     * and the petty-cash capture form legitimately offers several. But a
     * requisition asks a supplier for something, so offering it "Client refund /
     * credit note" is offering a wrong answer.
     *
     * Opt-in rather than default, so the screens that need the whole catalogue
     * keep getting it without asking.
     */
    private function applyProcurable(Request $request, $query): void
    {
        if ($request->boolean('procurable')) {
            $query->where('is_procurable', true);
        }
    }

    private function applyJobContext(Request $request, $query): void
    {
        if (! $request->has('job_context')) {
            return;
        }

        if ($request->boolean('job_context')) {
            $query->where('job_id_rule', '!=', ExpenseCode::JOB_NOT_ALLOWED);

            return;
        }

        $query->whereIn('job_id_rule', [
            ExpenseCode::JOB_NOT_ALLOWED,
            ExpenseCode::JOB_OPTIONAL,
            ExpenseCode::JOB_CONDITIONAL,
        ]);
    }

    public function show(string $code): JsonResponse
    {
        $expenseCode = ExpenseCode::active()->where('code', $code)->firstOrFail();

        return response()->json(['data' => new ExpenseCodeResource($expenseCode)]);
    }

    /**
     * Add a catalogue choice without asking the user to recreate accounting
     * controls. A known code is the template; only its human-facing identity is
     * new. This keeps GL, tax, evidence and job rules deliberate and consistent.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::FINANCE_EXPENSE_CODES_MANAGE), 403);

        $validated = $request->validate([
            'template_code' => ['required', 'string', Rule::exists('expense_codes', 'code')->where('is_active', true)],
            'expense_type' => ['required', 'string', 'min:3', 'max:190'],
            'simple_meaning' => ['required', 'string', 'min:10', 'max:500'],
            'example' => ['nullable', 'string', 'max:500'],
        ]);

        $template = ExpenseCode::active()->where('code', $validated['template_code'])->firstOrFail();

        $duplicate = ExpenseCode::query()
            ->where('expense_family', $template->expense_family)
            ->whereRaw('LOWER(expense_type) = ?', [Str::lower(trim($validated['expense_type']))])
            ->exists();
        if ($duplicate) {
            return response()->json([
                'message' => 'That expense type already exists in this category.',
                'errors' => ['expense_type' => ['Choose the existing type or use a distinct name.']],
            ], 422);
        }

        do {
            $code = 'CUS-' . Str::upper(Str::random(8));
        } while (ExpenseCode::where('code', $code)->exists());

        $expenseCode = $template->replicate();
        $expenseCode->fill([
            'code' => $code,
            'expense_type' => trim($validated['expense_type']),
            'simple_meaning' => trim($validated['simple_meaning']),
            'example' => filled($validated['example'] ?? null) ? trim($validated['example']) : null,
            'sort_order' => (int) ExpenseCode::where('expense_family', $template->expense_family)->max('sort_order') + 1,
            'is_active' => true,
        ]);
        $expenseCode->save();

        return response()->json([
            'message' => 'Expense type added with controls copied from ' . $template->expense_type . '.',
            'data' => new ExpenseCodeResource($expenseCode),
        ], 201);
    }
}
