<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\JwtTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiJwtAuthenticate
{
    public function __construct(private readonly JwtTokenService $jwtTokenService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->unauthenticatedResponse();
        }

        $payload = $this->jwtTokenService->parseAccessToken($token);
        if (! $payload || ! isset($payload['sub']) || ! is_numeric($payload['sub'])) {
            return $this->unauthenticatedResponse();
        }

        /** @var User|null $user */
        $user = User::query()
            ->whereKey((int) $payload['sub'])
            ->where('status', 'active')
            ->first();

        if (! $user) {
            return $this->unauthenticatedResponse();
        }

        $request->setUserResolver(static fn (): User => $user);

        return $next($request);
    }

    private function unauthenticatedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Unauthenticated.',
            'errors' => (object) [],
        ], 401);
    }
}
