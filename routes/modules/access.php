<?php

use App\Modules\Access\Controllers\AccessControlController;
use Illuminate\Support\Facades\Route;

Route::prefix('access')->group(function (): void {
    Route::get('permissions', [AccessControlController::class, 'permissions']);
    Route::get('roles', [AccessControlController::class, 'roles']);
    Route::post('roles', [AccessControlController::class, 'storeRole']);
    Route::put('roles/{roleId}', [AccessControlController::class, 'updateRole']);
    Route::delete('roles/{roleId}', [AccessControlController::class, 'destroyRole']);

    Route::get('users', [AccessControlController::class, 'users']);
    Route::put('users/{userId}/roles', [AccessControlController::class, 'syncUserRoles']);
});
