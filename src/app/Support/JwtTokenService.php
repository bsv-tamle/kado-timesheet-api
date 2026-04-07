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

    /**
     * @return array<string, mixed>|null
     */
    public function parseAccessToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $headerJson = $this->base64UrlDecode($encodedHeader);
        $payloadJson = $this->base64UrlDecode($encodedPayload);
        $signature = $this->base64UrlDecode($encodedSignature);

        if ($headerJson === null || $payloadJson === null || $signature === null) {
            return null;
        }

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);

        if (! is_array($header) || ! is_array($payload)) {
            return null;
        }

        if (($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $signingInput = $encodedHeader.'.'.$encodedPayload;
        $expectedSignature = hash_hmac('sha256', $signingInput, $this->secret(), true);

        if (! hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $now = time();
        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && (int) $payload['nbf'] > $now) {
            return null;
        }

        if (! isset($payload['exp']) || ! is_numeric($payload['exp']) || (int) $payload['exp'] <= $now) {
            return null;
        }

        return $payload;
    }

    private function base64UrlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
