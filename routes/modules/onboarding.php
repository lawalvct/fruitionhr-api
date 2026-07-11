<?php

use App\Modules\Tenancy\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;

Route::get('/onboarding', [OnboardingController::class, 'show']);
Route::patch('/onboarding', [OnboardingController::class, 'update']);
Route::post('/onboarding/complete', [OnboardingController::class, 'complete']);
Route::post('/onboarding/skip', [OnboardingController::class, 'skip']);
