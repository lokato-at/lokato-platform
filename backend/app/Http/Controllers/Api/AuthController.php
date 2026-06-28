<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AppLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login
     * Issues a Sanctum personal access token on valid credentials.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8|max:255',
            'device_name' => 'sometimes|string|max:64',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            AppLogger::event('auth', 'login_failed', [
                'email' => $data['email'],
                'ip' => $request->ip(),
            ], 'warning', true);

            throw ValidationException::withMessages([
                'email' => ['Die angegebenen Zugangsdaten sind ungültig.'],
            ]);
        }

        $deviceName = $data['device_name'] ?? ($request->userAgent() ?: 'unknown');
        $token = $user->createToken($deviceName)->plainTextToken;

        AppLogger::event('auth', 'login_success', [
            'user_id' => $user->id,
            'email' => $user->email,
            'device_name' => $deviceName,
            'ip' => $request->ip(),
        ], 'info', true);

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/logout
     * Revokes the current bearer token.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
            AppLogger::event('auth', 'logout', [
                'user_id' => $request->user()->id,
            ], 'info', true);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * GET /api/v1/auth/me
     * Returns the authenticated user's profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }
}
