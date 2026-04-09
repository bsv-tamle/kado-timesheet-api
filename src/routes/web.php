<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api-docs', function () {
    return view('api-docs');
});

Route::get('/openapi.yaml', function () {
    $path = resource_path('api/openapi.yaml');

    if (! file_exists($path)) {
        abort(404, 'OpenAPI spec not found.');
    }

    return response()->file($path, [
        'Content-Type' => 'application/yaml',
    ]);
})->name('openapi.yaml');
