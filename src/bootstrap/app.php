<?php

use App\Http\Middleware\ApiJwtAuthenticate;
use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsureEmployeeRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Allow proxy request (like AWS Load Balancer)
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO |
                Request::HEADER_X_FORWARDED_AWS_ELB
        );

        // Disable redirect mechanism
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        $middleware->alias([
            'api.jwt' => ApiJwtAuthenticate::class,
            'role.admin' => EnsureAdminRole::class,
            'role.employee' => EnsureEmployeeRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always render error as json
        $exceptions->shouldRenderJsonWhen(function () {
            return true;
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Unauthenticated.',
                'errors' => (object) [],
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Forbidden.',
                'errors' => (object) [],
            ], 403);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Resource not found.',
                'errors' => (object) [],
            ], 404);
        });

        // HttpException
        $exceptions->render(function (HttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => (object) [],
            ], $e->getStatusCode());
        });

        // Unexpected Exception
        $exceptions->render(function (Throwable $e) {
            return response()->json([
                'message' => app()->hasDebugModeEnabled() ? $e->getMessage() : 'Internal server error',
                'errors' => (object) [],
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        });
    })->create();
