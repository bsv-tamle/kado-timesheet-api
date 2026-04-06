<?php

namespace App\Support;

use App\Models\User;

class JwtTokenService
{
    public function createAccessToken(User $user, int $ttlSeconds = 3600): string
    {
        $now = time();
        $payload = [
            'iss' => config('app.url', 'kado-timesheet-api'),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'sub' => $user->id,
            'role' => $user->role,
            'email' => $user->email,
        ];

        return $this->encode($payload, $this->secret());
    }

    private function secret(): string
    {
        return (string) (config('app.key') ?: 'kado-local-jwt-secret');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload, string $secret): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        $segments = [];
        $segments[] = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $segments[] = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
