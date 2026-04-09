<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PasswordResetMailer;
use App\Models\User;
use App\Support\JwtTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

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

    public function forgotPassword(Request $request, PasswordResetMailer $passwordResetMailer): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = strtolower(trim((string) $validated['email']));
        $throttleSeconds = (int) config('auth.passwords.users.throttle', 60);
        $rateLimitKey = 'auth-forgot-password:'.sha1($email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($rateLimitKey, 1)) {
            return $this->errorResponse('Too many reset requests. Please try again later.', 429);
        }

        RateLimiter::hit($rateLimitKey, max(1, $throttleSeconds));

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user) {
            try {
                $token = Password::createToken($user);
                $passwordResetMailer->sendResetLink($user, $token);
            } catch (\Throwable $e) {
                Log::warning('Failed to send password reset email.', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->successResponse(
            ['sent' => true],
            'If this email exists, reset instructions have been sent.'
        );
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $this->normalizeResetPasswordRequest($request);

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', strtolower(trim((string) $validated['email'])))->first();
        if (! $user) {
            return $this->errorResponse('Reset token is invalid or expired.', 410);
        }

        if (! Password::tokenExists($user, (string) $validated['token'])) {
            return $this->errorResponse('Reset token is invalid or expired.', 410);
        }

        $user->forceFill([
            'password' => Hash::make((string) $validated['password']),
            'must_change_password' => false,
        ])->save();

        Password::deleteToken($user);

        return $this->successResponse([
            'reset' => true,
        ], 'Password reset successfully');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $this->normalizeChangePasswordRequest($request);

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'max:255', 'different:current_password', 'confirmed'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check((string) $validated['current_password'], (string) $user->password)) {
            return $this->errorResponse('Validation error', 422, [
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make((string) $validated['new_password']),
            'must_change_password' => false,
        ])->save();

        return $this->successResponse([
            'changed' => true,
        ], 'Password changed successfully');
    }

    private function normalizeResetPasswordRequest(Request $request): void
    {
        if (! $request->has('password') && $request->has('new_password')) {
            $request->merge([
                'password' => $request->input('new_password'),
            ]);
        }

        if (! $request->has('password_confirmation')) {
            $request->merge([
                'password_confirmation' => $request->input('confirm_password', $request->input('new_password_confirmation')),
            ]);
        }
    }

    private function normalizeChangePasswordRequest(Request $request): void
    {
        if (! $request->has('new_password') && $request->has('password')) {
            $request->merge([
                'new_password' => $request->input('password'),
            ]);
        }

        if (! $request->has('new_password_confirmation')) {
            $request->merge([
                'new_password_confirmation' => $request->input('confirm_password', $request->input('password_confirmation')),
            ]);
        }
    }
}
