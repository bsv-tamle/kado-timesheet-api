<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminEmployeeProjectController;
use App\Http\Controllers\Api\AdminProjectController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\EmployeeTimesheetController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware(['api.jwt'])->group(function (): void {
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    });

    Route::middleware(['api.jwt', 'role.admin'])
        ->prefix('admin')
        ->group(function (): void {
            Route::get('/users', [AdminUserController::class, 'index']);
            Route::get('/users/{id}', [AdminUserController::class, 'show']);
            Route::post('/users', [AdminUserController::class, 'store']);
            Route::put('/users/{id}', [AdminUserController::class, 'update']);
            Route::patch('/users/{id}/status', [AdminUserController::class, 'updateStatus']);
            Route::post('/users/{id}/reset-password', [AdminUserController::class, 'resetPassword']);

            Route::get('/projects', [AdminProjectController::class, 'index']);
            Route::get('/projects/{id}', [AdminProjectController::class, 'show']);
            Route::post('/projects', [AdminProjectController::class, 'store']);
            Route::put('/projects/{id}', [AdminProjectController::class, 'update']);
            Route::patch('/projects/{id}/status', [AdminProjectController::class, 'updateStatus']);

            Route::get('/employee-projects', [AdminEmployeeProjectController::class, 'index']);
            Route::post('/employee-projects/assign', [AdminEmployeeProjectController::class, 'assign']);
            Route::post('/employee-projects/unassign', [AdminEmployeeProjectController::class, 'unassign']);
        });

    Route::middleware(['api.jwt', 'role.employee'])->group(function (): void {
        Route::get('/my-projects', [EmployeeTimesheetController::class, 'myProjects']);
        Route::get('/timesheets', [EmployeeTimesheetController::class, 'index']);
        Route::post('/timesheets', [EmployeeTimesheetController::class, 'store']);
        Route::put('/timesheets/{entryId}', [EmployeeTimesheetController::class, 'update']);
        Route::delete('/timesheets/details/{detailId}', [EmployeeTimesheetController::class, 'destroyDetail']);
    });
});
