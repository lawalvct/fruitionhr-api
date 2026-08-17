<?php

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\EmailVerificationController;
use App\Modules\Auth\Controllers\EssInvitationController;
use App\Modules\Auth\Controllers\ProfileController;
use App\Modules\Billing\Controllers\PaymentWebhookController;
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

    Route::post('/ess-invitations/accept', [EssInvitationController::class, 'accept'])
        ->middleware('throttle:10,1')
        ->name('v1.ess-invitations.accept');

    require __DIR__.'/modules/reference.php';
    require __DIR__.'/modules/public-recruitment.php';
    require __DIR__.'/modules/public-blog.php';
    require __DIR__.'/modules/public-billing.php';

    // Gateway webhooks. No auth: the caller is Paystack/Nomba, not a user —
    // the HMAC signature check inside the controller is the gate.
    Route::post('/webhooks/{gateway}', PaymentWebhookController::class)
        ->where('gateway', '[a-z]+')
        ->middleware('throttle:120,1')
        ->name('v1.webhooks');
});

/*
|--------------------------------------------------------------------------
| Authenticated — any user (tenant or super admin)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('v1.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('v1.me');

    // Self-service account editing (any authenticated user, incl. super admins).
    Route::put('/profile', [ProfileController::class, 'update'])->name('v1.profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])
        ->middleware('throttle:6,1')
        ->name('v1.profile.password');
    Route::get('/profile/avatar', [ProfileController::class, 'showAvatar'])->name('v1.profile.avatar.show');
    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar'])->name('v1.profile.avatar.upload');
    Route::delete('/profile/avatar', [ProfileController::class, 'deleteAvatar'])->name('v1.profile.avatar.delete');
    Route::post('/email/verify', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('v1.email.verify');
    Route::post('/email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:3,1')
        ->name('v1.email.resend');
});

/*
|--------------------------------------------------------------------------
| Tenant API — requires an active tenant context
|--------------------------------------------------------------------------
| All company-facing module routes register inside this group.
*/
// Billing is deliberately outside the verified.email group below: a tenant
// that cannot yet use the product must still be able to see and pay its bill.
Route::prefix('v1')->middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    require __DIR__.'/modules/billing.php';
    // Support is deliberately here too: someone who cannot pay, or cannot
    // verify their email, is precisely who most needs to reach us.
    require __DIR__.'/modules/support.php';
});

Route::prefix('v1')->middleware(['auth:sanctum', 'tenant', 'verified.email', 'subscribed'])->group(function (): void {
    require __DIR__.'/modules/onboarding.php';
    require __DIR__.'/modules/core.php';
    require __DIR__.'/modules/company.php';
    require __DIR__.'/modules/access.php';
    require __DIR__.'/modules/employees.php';
    require __DIR__.'/modules/attendance.php';
    require __DIR__.'/modules/leave.php';
    require __DIR__.'/modules/payroll.php';
    require __DIR__.'/modules/self-service.php';
    require __DIR__.'/modules/recruitment.php';
    require __DIR__.'/modules/performance.php';
    require __DIR__.'/modules/reports.php';
});

/*
|--------------------------------------------------------------------------
| Super-admin API — FruitionHR staff only
|--------------------------------------------------------------------------
*/
Route::prefix('admin/v1')->middleware(['auth:sanctum', 'super-admin'])->group(function (): void {
    require __DIR__.'/modules/admin.php';
});
