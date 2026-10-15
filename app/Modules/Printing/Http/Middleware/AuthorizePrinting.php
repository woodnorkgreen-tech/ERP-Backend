<?php

namespace App\Modules\Printing\Http\Middleware;

use App\Modules\Printing\Support\PrintingAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizePrinting
{
    public function handle(Request $request, Closure $next, string $permission, string $scope = 'staff'): Response
    {
        $user = $request->user();

        if ($scope === 'stores') {
            abort_unless($user?->hasAnyRole(['Stores', 'Manager', 'Super Admin']), 403);
        } elseif ($scope === 'stores-read') {
            abort_unless(
                $user?->hasAnyRole(['Stores', 'Manager', 'Super Admin'])
                    || PrintingAccess::allows($user, $permission),
                403
            );
        } else {
            abort_unless(PrintingAccess::allows($user, $permission, $scope === 'lead'), 403);
        }

        return $next($request);
    }
}
