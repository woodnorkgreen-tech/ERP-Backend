<?php

namespace App\Modules\Finance\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentReversalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PaymentController extends Controller
{
    public function reverse(Request $request, Payment $payment, PaymentReversalService $reversal): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::FINANCE_PAYMENTS_REVERSE), 403);

        $validated = $request->validate(['reason' => 'required|string|min:5|max:500']);

        try {
            $payment = $reversal->reverse($payment, $request->user()->id, $validated['reason']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => "Payment {$payment->payment_no} reversed. Original records were retained.",
            'data' => $payment,
        ]);
    }
}
