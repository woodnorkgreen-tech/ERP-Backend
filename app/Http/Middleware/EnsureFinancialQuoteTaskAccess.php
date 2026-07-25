<?php

namespace App\Http\Middleware;

use App\Constants\EnquiryConstants;
use App\Modules\Projects\Models\EnquiryTask;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFinancialQuoteTaskAccess
{
    public function handle(Request $request, Closure $next, ?string $expectedType = null): Response
    {
        $user = $request->user();
        abort_unless(
            $user && $user->hasRole(EnquiryConstants::FINANCIAL_QUOTE_ROLES),
            403,
            'Quote access is restricted to Finance, Costing, and Project Managers.'
        );

        if ($expectedType !== null) {
            $task = EnquiryTask::whereKey((int) $request->route('taskId'))
                ->where('type', $expectedType)
                ->firstOrFail();
            $request->attributes->set('financial_quote_task', $task);
        }

        return $next($request);
    }
}
