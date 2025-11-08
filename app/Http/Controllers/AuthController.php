<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * @OA\Info(
 *     title="LaravelVueErp API",
 *     version="1.0.0",
 *     description="API Documentation for LaravelVueErp Project Management System"
 * )
 *
 * @OA\Server(
 *     url="/api",
 *     description="API Server"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 */
class AuthController extends Controller
{
    /**
     * @OA\Post(
     *     path="/register",
     *     summary="Register a new user",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name","email","password","password_confirmation"},
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", format="email", example="john@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="password123"),
     *             @OA\Property(property="password_confirmation", type="string", example="password123")
     *         )
     *     ),
     *     @OA\Response(response="201", description="User registered successfully"),
     *     @OA\Response(response="422", description="Validation error")
     * )
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|confirmed|min:6',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token], 201);
    }

    /**
     * @OA\Post(
     *     path="/login",
     *     summary="Authenticate user and get token",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email", type="string", format="email", example="john@example.com"),
     *             @OA\Property(property="password", type="string", example="password123")
     *         )
     *     ),
     *     @OA\Response(response="200", description="Login successful"),
     *     @OA\Response(response="401", description="Invalid credentials")
     * )
     */
    public function login(Request $request)
    {
        \Log::info('Login attempt started', ['email' => $request->email]);

        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        \Log::info('Validation passed for login', ['email' => $request->email]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            \Log::warning('User not found', ['email' => $request->email]);
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        \Log::info('User found', ['email' => $request->email, 'user_id' => $user->id]);

        if (!Hash::check($request->password, $user->password)) {
            \Log::warning('Password check failed', ['email' => $request->email, 'user_id' => $user->id]);
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        \Log::info('Password check passed, creating token', ['email' => $request->email, 'user_id' => $user->id]);

        $token = $user->createToken('api-token')->plainTextToken;

        \Log::info('Token created successfully', ['email' => $request->email, 'user_id' => $user->id]);

        return response()->json(['user' => $user, 'token' => $token]);
    }

    /**
     * @OA\Post(
     *     path="/logout",
     *     summary="Logout user and revoke token",
     *     tags={"Authentication"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response="200", description="Logout successful")
     * )
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}

