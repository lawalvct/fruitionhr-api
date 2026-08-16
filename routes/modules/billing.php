<?php

use App\Modules\Billing\Controllers\BillingController;
use Illuminate\Support\Facades\Route;

Route::get('billing/plans', [BillingController::class, 'plans'])->name('v1.billing.plans');
Route::get('billing/subscription', [BillingController::class, 'subscription'])->name('v1.billing.subscription');
Route::post('billing/subscribe', [BillingController::class, 'subscribe'])->name('v1.billing.subscribe');
Route::post('billing/subscription/cancel', [BillingController::class, 'cancel'])->name('v1.billing.cancel');

Route::get('billing/payments', [BillingController::class, 'payments'])->name('v1.billing.payments');
Route::post('billing/payments', [BillingController::class, 'pay'])
    ->middleware('throttle:10,1')
    ->name('v1.billing.pay');

// Confirms a charge after the customer returns from the gateway. The reference
// is only a lookup key — the amount and status are re-read from the gateway.
Route::post('billing/payments/{reference}/verify', [BillingController::class, 'verify'])
    ->where('reference', '[A-Za-z0-9_\-]+')
    ->middleware('throttle:20,1')
    ->name('v1.billing.payments.verify');
