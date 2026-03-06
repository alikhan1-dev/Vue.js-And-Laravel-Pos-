<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming API authentication request.
     */
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        /** @var \App\Models\User|null $user */
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password) || ! $user->is_active) {
            return response()->json([
                'message' => 'The provided credentials are incorrect or the user is inactive.',
            ], 422);
        }

        // Update audit fields for successful login
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        // Revoke existing tokens for this device if needed
        $user->tokens()->where('name', $request->input('device_name', 'web'))->delete();

        $token = $user->createToken($request->input('device_name', 'web'))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->load('company', 'roles'),
        ]);
    }

    /**
     * Destroy an authenticated API session (revoke token).
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}

