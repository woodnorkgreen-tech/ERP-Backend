<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\PettyCash\Models\PettyCashBalance;
use App\Modules\Finance\Support\PaymentMethods;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The paying-account master: every account WNG's money can leave.
 *
 * One endpoint, replacing four that served the same rows behind four different
 * permissions and in three different shapes — the spend-voucher form, the
 * receivables screen, the bill-payment screen and the petty-cash top-up form
 * each had their own. Adding a bank account was a developer's job because none
 * of them could write.
 *
 * Reading is open to any authenticated user: a clerk recording a payment must
 * be able to name the account it came from. Writing is a Finance control,
 * because gl_account_id decides which ledger account the payment credits.
 */
class PaymentSourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sources = PaymentSource::query()
            ->with('glAccount:id,code,name')
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        // The float balance rides along with the source rather than sitting
        // behind finance.petty_cash.view, because the person choosing an account
        // to pay from needs to know whether there is money in it — and a
        // procurement clerk holds no petty cash permission.
        $float = PettyCashBalance::current()?->current_balance;

        return response()->json([
            'status' => 'success',
            'data' => $sources->map(fn (PaymentSource $source) => [
                'id' => $source->id,
                'code' => $source->code,
                'name' => $source->name,
                'type' => $source->type,
                'currency' => $source->currency,
                'is_active' => (bool) $source->is_active,
                'gl_account' => $source->glAccount?->only(['id', 'code', 'name']),
                // Null everywhere else, deliberately: a bank balance is not held
                // in this system, and a zero would read as "no money" rather
                // than "not tracked here".
                'available_balance' => $source->type === 'petty_cash' ? (float) ($float ?? 0) : null,
                'typical_methods' => PaymentMethods::TYPICAL_FOR_SOURCE_TYPE[$source->type] ?? [],
            ]),
            'meta' => [
                'can_manage' => (bool) $request->user()?->can(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE),
                'types' => ['petty_cash', 'bank', 'mobile_money', 'card', 'payable'],
            ],
        ]);
    }

    /**
     * How money can be transmitted. Controlled, not master data.
     *
     * Served here and nowhere else. Petty cash and procurement each had their
     * own endpoint for this, and procurement's read a table anyone could append
     * to — which is how three bank accounts ended up in a list of methods.
     */
    public function paymentMethods(): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => PaymentMethods::options()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE), 403);

        $data = $request->validate($this->rules());
        $source = PaymentSource::create($data + ['is_active' => $request->boolean('is_active', true)]);

        return response()->json([
            'status' => 'success',
            'message' => "{$source->name} is now available to pay from.",
            'data' => $source->load('glAccount:id,code,name'),
        ], 201);
    }

    public function update(Request $request, PaymentSource $paymentSource): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE), 403);

        $paymentSource->update($request->validate($this->rules($paymentSource->id)));

        return response()->json([
            'status' => 'success',
            'message' => "{$paymentSource->name} updated.",
            'data' => $paymentSource->fresh()->load('glAccount:id,code,name'),
        ]);
    }

    /**
     * Ledger accounts an account may be mapped to.
     *
     * Only postable asset and liability accounts: a payment source names where
     * cash sits, so mapping one to an expense account would credit the wrong
     * side of every payment made through it.
     */
    public function ledgerAccounts(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYMENT_SOURCES_MANAGE), 403);

        return response()->json([
            'status' => 'success',
            'data' => ChartOfAccount::postable()
                ->where('account_type', 'balance_sheet')
                ->whereIn('category', ['asset', 'liability'])
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'category']),
        ]);
    }

    private function rules(?int $ignoreId = null): array
    {
        return [
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Z0-9-]+$/',
                Rule::unique('payment_sources', 'code')->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['petty_cash', 'bank', 'mobile_money', 'card', 'payable'])],
            // Required: an account with no ledger account cannot say what a
            // payment through it credits, and JournalPostingService would fall
            // back to Accounts Payable for spend that never touched a supplier.
            'gl_account_id' => [
                'required',
                'integer',
                Rule::exists('chart_of_accounts', 'id')->where(
                    fn ($query) => $query
                        ->where('is_active', true)
                        ->where('is_postable', true)
                        ->where('account_type', 'balance_sheet')
                        ->whereIn('category', ['asset', 'liability']),
                ),
            ],
            'currency' => ['nullable', 'string', 'size:3'],
            'float_limit' => ['nullable', 'numeric', 'min:0'],
            'custodian_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
