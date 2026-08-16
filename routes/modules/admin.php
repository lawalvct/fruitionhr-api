<?php

use App\Modules\Admin\Controllers\PlatformActivityController;
use App\Modules\Admin\Controllers\PlatformAdministratorController;
use App\Modules\Admin\Controllers\PlatformDashboardController;
use App\Modules\Admin\Controllers\PlatformRecruitmentController;
use App\Modules\Admin\Controllers\PlatformTenantController;
use App\Modules\Admin\Controllers\PlatformUserController;
use App\Modules\Billing\Controllers\AdminBillingController;
use App\Modules\Content\Controllers\BlogMediaController;
use App\Modules\Content\Controllers\BlogPostController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', PlatformDashboardController::class)
    ->name('admin.v1.dashboard');

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

Route::get('activity', [PlatformActivityController::class, 'index'])
    ->name('admin.v1.activity.index');

Route::get('blog-posts', [BlogPostController::class, 'index'])
    ->name('admin.v1.blog-posts.index');
Route::post('blog-posts', [BlogPostController::class, 'store'])
    ->name('admin.v1.blog-posts.store');
Route::get('blog-posts/{post}', [BlogPostController::class, 'show'])
    ->whereNumber('post')
    ->name('admin.v1.blog-posts.show');
Route::put('blog-posts/{post}', [BlogPostController::class, 'update'])
    ->whereNumber('post')
    ->name('admin.v1.blog-posts.update');
Route::delete('blog-posts/{post}', [BlogPostController::class, 'destroy'])
    ->whereNumber('post')
    ->name('admin.v1.blog-posts.destroy');

// Cover-image uploads. Declared before the {post} routes so "media" is never
// swallowed by the numeric-constrained show/update routes.
Route::post('blog-posts/media', [BlogMediaController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('admin.v1.blog-posts.media.store');
Route::delete('blog-posts/media', [BlogMediaController::class, 'destroy'])
    ->name('admin.v1.blog-posts.media.destroy');

// Careers oversight — read-only, spans every tenant.
Route::get('recruitment/vacancies', [PlatformRecruitmentController::class, 'vacancies'])
    ->name('admin.v1.recruitment.vacancies');
Route::get('recruitment/vacancies/{vacancy}', [PlatformRecruitmentController::class, 'vacancy'])
    ->whereNumber('vacancy')
    ->name('admin.v1.recruitment.vacancy');
Route::get('recruitment/applications', [PlatformRecruitmentController::class, 'applications'])
    ->name('admin.v1.recruitment.applications');

// Platform-wide user directory for support.
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

// Billing: the price list, and subscriptions across every tenant.
Route::get('billing/plans', [AdminBillingController::class, 'plans'])
    ->name('admin.v1.billing.plans');
Route::post('billing/plans', [AdminBillingController::class, 'storePlan'])
    ->name('admin.v1.billing.plans.store');
Route::put('billing/plans/{plan}', [AdminBillingController::class, 'updatePlan'])
    ->whereNumber('plan')
    ->name('admin.v1.billing.plans.update');
Route::get('billing/subscriptions', [AdminBillingController::class, 'subscriptions'])
    ->name('admin.v1.billing.subscriptions');
