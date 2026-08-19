<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Models\UserDeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Separate device-token registration endpoint.
 *
 * Unlike the original /user/device-token route (which matches on
 * user_id + player_id and therefore accumulates a new row every time
 * OneSignal issues a fresh subscription id), this matches on
 * user_id + platform — so each device always has exactly one current,
 * valid token. Kept as its own controller so nothing about the
 * original endpoint or its callers changes.
 */
class PushRegistrationController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'player_id' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web'])],
        ]);

        $token = UserDeviceToken::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'],
            ],
            ['player_id' => $validated['player_id']]
        );

        return response()->json(['data' => $token], 201);
    }
}