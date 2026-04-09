<?php

namespace App\Http\Middleware;

use Illuminate\Http\JsonResponse;

trait ReturnsForbiddenResponse
{
    private function forbiddenResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Forbidden.',
            'errors' => (object) [],
        ], 403);
    }
}
