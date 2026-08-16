<?php

use App\Modules\Billing\Controllers\PublicPlanController;
use Illuminate\Support\Facades\Route;

// Powers the marketing pricing page. No auth — anonymous visitors.
Route::get('plans', [PublicPlanController::class, 'index'])
    ->middleware('throttle:120,1')
    ->name('v1.plans.index');
