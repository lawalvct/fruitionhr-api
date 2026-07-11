<?php

use App\Modules\Performance\Controllers\AppraisalController;
use App\Modules\Performance\Controllers\GoalController;
use App\Modules\Performance\Controllers\PerformanceSetupController;
use Illuminate\Support\Facades\Route;

Route::get('performance/categories', [PerformanceSetupController::class, 'categories']);
Route::post('performance/categories', [PerformanceSetupController::class, 'storeCategory']);
Route::get('performance/kpis', [PerformanceSetupController::class, 'kpis']);
Route::post('performance/kpis', [PerformanceSetupController::class, 'storeKpi']);
Route::get('performance/rating-scales', [PerformanceSetupController::class, 'ratingScales']);
Route::post('performance/rating-scales', [PerformanceSetupController::class, 'storeRatingScale']);
Route::get('performance/templates', [PerformanceSetupController::class, 'templates']);
Route::post('performance/templates', [PerformanceSetupController::class, 'storeTemplate']);
Route::get('performance/cycles', [PerformanceSetupController::class, 'cycles']);
Route::post('performance/cycles', [PerformanceSetupController::class, 'storeCycle']);
Route::post('performance/cycles/{cycle}/{action}', [PerformanceSetupController::class, 'cycleAction']);

Route::get('performance/assignments', [AppraisalController::class, 'index']);
Route::post('performance/assignments', [AppraisalController::class, 'store']);
Route::get('performance/assignments/{assignment}', [AppraisalController::class, 'show']);
Route::post('performance/assignments/{assignment}/reviewers/{reviewer}/submit', [AppraisalController::class, 'submitReview']);
Route::post('performance/results/{result}/outcomes', [AppraisalController::class, 'addOutcome']);

Route::get('goals', [GoalController::class, 'index']);
Route::post('goals', [GoalController::class, 'store']);
Route::put('goals/{goal}', [GoalController::class, 'update']);
Route::post('goals/{goal}/check-ins', [GoalController::class, 'checkin']);
