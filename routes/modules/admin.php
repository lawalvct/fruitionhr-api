<?php

use App\Modules\Admin\Controllers\PlatformActivityController;
use App\Modules\Admin\Controllers\PlatformAdministratorController;
use App\Modules\Admin\Controllers\PlatformDashboardController;
use App\Modules\Admin\Controllers\PlatformRecruitmentController;
use App\Modules\Admin\Controllers\PlatformRoleController;
use App\Modules\Admin\Controllers\PlatformTenantController;
use App\Modules\Admin\Controllers\PlatformUserController;
use App\Modules\Billing\Controllers\AdminBillingController;
use App\Modules\Billing\Controllers\AdminRevenueController;
use App\Modules\Content\Controllers\BlogMediaController;
use App\Modules\Content\Controllers\BlogPostController;
use App\Modules\Support\Controllers\AdminSupportController;
use App\Support\Authorization\PlatformAbilities;
use Illuminate\Support\Facades\Route;

/*
 * Every route here sits behind one platform ability, grouped the way the admin
 * sidebar is. Adding a route means putting it inside a group — an ungrouped
 * admin route is reachable by every member of staff, whatever the sidebar
 * shows them. See App\Support\Authorization\PlatformAbilities.
 */

Route::middleware('platform:'.PlatformAbilities::DASHBOARD)->group(function (): void {
    Route::get('dashboard', PlatformDashboardController::class)
        ->name('admin.v1.dashboard');
});

Route::middleware('platform:'.PlatformAbilities::TENANTS)->group(function (): void {
    Route::get('tenants', [PlatformTenantController::class, 'index'])
        ->name('admin.v1.tenants.index');
    Route::get('tenants/{tenant}', [PlatformTenantController::class, 'show'])
        ->whereNumber('tenant')
        ->name('admin.v1.tenants.show');
    Route::put('tenants/{tenant}', [PlatformTenantController::class, 'update'])
        ->whereNumber('tenant')
        ->name('admin.v1.tenants.update');
    Route::post('tenants/{tenant}/suspend', [PlatformTenantController::class, 'suspend'])
        ->whereNumber('tenant')
        ->name('admin.v1.tenants.suspend');
    Route::post('tenants/{tenant}/activate', [PlatformTenantController::class, 'activate'])
        ->whereNumber('tenant')
        ->name('admin.v1.tenants.activate');
});

/*
 * Owners only. This is the power to grant power, so it is never itself
 * grantable: PlatformAbilities marks it unassignable, which means no custom
 * role can carry it and the only way to create another owner is to hand
 * somebody the Owner role.
 */
Route::middleware('platform:'.PlatformAbilities::ADMINISTRATORS)->group(function (): void {
    Route::get('administrators', [PlatformAdministratorController::class, 'index'])
        ->name('admin.v1.administrators.index');
    Route::post('administrators', [PlatformAdministratorController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('admin.v1.administrators.store');
    Route::put('administrators/{administrator}', [PlatformAdministratorController::class, 'update'])
        ->whereNumber('administrator')
        ->name('admin.v1.administrators.update');
    Route::post('administrators/{administrator}/disable', [PlatformAdministratorController::class, 'disable'])
        ->whereNumber('administrator')
        ->name('admin.v1.administrators.disable');
    Route::post('administrators/{administrator}/activate', [PlatformAdministratorController::class, 'activate'])
        ->whereNumber('administrator')
        ->name('admin.v1.administrators.activate');

    Route::get('platform-roles', [PlatformRoleController::class, 'index'])
        ->name('admin.v1.platform-roles.index');
    Route::post('platform-roles', [PlatformRoleController::class, 'store'])
        ->name('admin.v1.platform-roles.store');
    Route::put('platform-roles/{role}', [PlatformRoleController::class, 'update'])
        ->whereNumber('role')
        ->name('admin.v1.platform-roles.update');
    Route::delete('platform-roles/{role}', [PlatformRoleController::class, 'destroy'])
        ->whereNumber('role')
        ->name('admin.v1.platform-roles.destroy');
});

Route::middleware('platform:'.PlatformAbilities::ACTIVITY)->group(function (): void {
    Route::get('activity', [PlatformActivityController::class, 'index'])
        ->name('admin.v1.activity.index');
});

Route::middleware('platform:'.PlatformAbilities::BLOG)->group(function (): void {
    Route::get('blog-posts', [BlogPostController::class, 'index'])
        ->name('admin.v1.blog-posts.index');
    Route::post('blog-posts', [BlogPostController::class, 'store'])
        ->name('admin.v1.blog-posts.store');

    // Cover-image uploads. Declared before the {post} routes so "media" is never
    // swallowed by the numeric-constrained show/update routes.
    Route::post('blog-posts/media', [BlogMediaController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('admin.v1.blog-posts.media.store');
    Route::delete('blog-posts/media', [BlogMediaController::class, 'destroy'])
        ->name('admin.v1.blog-posts.media.destroy');

    Route::get('blog-posts/{post}', [BlogPostController::class, 'show'])
        ->whereNumber('post')
        ->name('admin.v1.blog-posts.show');
    Route::put('blog-posts/{post}', [BlogPostController::class, 'update'])
        ->whereNumber('post')
        ->name('admin.v1.blog-posts.update');
    Route::delete('blog-posts/{post}', [BlogPostController::class, 'destroy'])
        ->whereNumber('post')
        ->name('admin.v1.blog-posts.destroy');
});

// Careers oversight — read-only, spans every tenant.
Route::middleware('platform:'.PlatformAbilities::CAREERS)->group(function (): void {
    Route::get('recruitment/vacancies', [PlatformRecruitmentController::class, 'vacancies'])
        ->name('admin.v1.recruitment.vacancies');
    Route::get('recruitment/vacancies/{vacancy}', [PlatformRecruitmentController::class, 'vacancy'])
        ->whereNumber('vacancy')
        ->name('admin.v1.recruitment.vacancy');
    Route::get('recruitment/applications', [PlatformRecruitmentController::class, 'applications'])
        ->name('admin.v1.recruitment.applications');
});

// Platform-wide user directory for support.
Route::middleware('platform:'.PlatformAbilities::USERS)->group(function (): void {
    Route::get('users', [PlatformUserController::class, 'index'])
        ->name('admin.v1.users.index');
    Route::get('users/{user}', [PlatformUserController::class, 'show'])
        ->whereNumber('user')
        ->name('admin.v1.users.show');
    Route::post('users/{user}/verify-email', [PlatformUserController::class, 'verifyEmail'])
        ->whereNumber('user')
        ->name('admin.v1.users.verify-email');
    Route::post('users/{user}/reset-password', [PlatformUserController::class, 'resetPassword'])
        ->whereNumber('user')
        ->middleware('throttle:10,1')
        ->name('admin.v1.users.reset-password');
});

// Billing: the price list, and subscriptions across every tenant.
Route::middleware('platform:'.PlatformAbilities::BILLING)->group(function (): void {
    Route::get('billing/plans', [AdminBillingController::class, 'plans'])
        ->name('admin.v1.billing.plans');
    Route::post('billing/plans', [AdminBillingController::class, 'storePlan'])
        ->name('admin.v1.billing.plans.store');
    Route::put('billing/plans/{plan}', [AdminBillingController::class, 'updatePlan'])
        ->whereNumber('plan')
        ->name('admin.v1.billing.plans.update');
    Route::get('billing/subscriptions', [AdminBillingController::class, 'subscriptions'])
        ->name('admin.v1.billing.subscriptions');
    Route::get('billing/gateways', [AdminBillingController::class, 'gateways'])
        ->name('admin.v1.billing.gateways');
    Route::put('billing/gateways', [AdminBillingController::class, 'updateGateways'])
        ->name('admin.v1.billing.gateways.update');
});

// What the platform earns. A separate ability from billing: running the price
// list and knowing the company's income are different jobs.
Route::middleware('platform:'.PlatformAbilities::REVENUE)->group(function (): void {
    Route::get('revenue', [AdminRevenueController::class, 'overview'])
        ->name('admin.v1.revenue.overview');
    Route::get('revenue/companies', [AdminRevenueController::class, 'companies'])
        ->name('admin.v1.revenue.companies');
});

// Support queue across every tenant.
Route::middleware('platform:'.PlatformAbilities::SUPPORT)->group(function (): void {
    Route::get('support/tickets', [AdminSupportController::class, 'index'])
        ->name('admin.v1.support.index');
    Route::get('support/summary', [AdminSupportController::class, 'summary'])
        ->name('admin.v1.support.summary');
    Route::get('support/tickets/{ticket}', [AdminSupportController::class, 'show'])
        ->whereNumber('ticket')
        ->name('admin.v1.support.show');
    Route::post('support/tickets/{ticket}/messages', [AdminSupportController::class, 'reply'])
        ->whereNumber('ticket')
        ->name('admin.v1.support.reply');
    Route::post('support/tickets/{ticket}/status', [AdminSupportController::class, 'updateStatus'])
        ->whereNumber('ticket')
        ->name('admin.v1.support.status');
    Route::post('support/tickets/{ticket}/assign', [AdminSupportController::class, 'assign'])
        ->whereNumber('ticket')
        ->name('admin.v1.support.assign');
});
