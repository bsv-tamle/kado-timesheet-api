<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmployeeRole
{
    use ReturnsForbiddenResponse;
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'employee') {
            return $this->forbiddenResponse();
        }

        return $next($request);
    }
}

