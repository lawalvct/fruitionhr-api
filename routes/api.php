<?php

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Tenancy\Controllers\RegisterTenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public (unauthenticated)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function (): void {
    Route::post('/register', RegisterTenantController::class)
        ->middleware('throttle:6,1')
        ->name('v1.register');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('v1.login');
});

/*
|--------------------------------------------------------------------------
| Authenticated — any user (tenant or super admin)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('v1.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('v1.me');
});

/*
|--------------------------------------------------------------------------
| Tenant API — requires an active tenant context
|--------------------------------------------------------------------------
| All company-facing module routes register inside this group.
*/
Route::prefix('v1')->middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    require __DIR__.'/modules/core.php';
    require __DIR__.'/modules/company.php';
    require __DIR__.'/modules/employees.php';
    require __DIR__.'/modules/attendance.php';
    require __DIR__.'/modules/leave.php';
    require __DIR__.'/modules/payroll.php';
});

/*
|--------------------------------------------------------------------------
| Super-admin API — FruitionHR staff only
|--------------------------------------------------------------------------
*/
Route::prefix('admin/v1')->middleware(['auth:sanctum', 'super-admin'])->group(function (): void {
    // Tenant management endpoints land here.
});
