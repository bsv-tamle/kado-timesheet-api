<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\JwtTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends ApiController
{
    public function login(Request $request, JwtTokenService $jwt): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return $this->errorResponse('Invalid credentials.', 401);
        }

        if ($user->status !== 'active') {
            return $this->errorResponse('Account is not active.', 403);
        }

        $expiresIn = (int) config('auth.jwt_ttl_seconds', 3600);
        $accessToken = $jwt->createAccessToken($user, $expiresIn);

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->successResponse([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'role' => $user->role,
                'must_change_password' => (bool) $user->must_change_password,
            ],
        ], 'Login success');
    }
}
