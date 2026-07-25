<?php

use App\Modules\Performance\Controllers\AppraisalController;
use App\Modules\Performance\Controllers\GoalController;
use App\Modules\Performance\Controllers\PerformanceReportController;
use App\Modules\Performance\Controllers\PerformanceSetupController;
use App\Modules\Performance\Controllers\PipController;
use App\Modules\Performance\Controllers\ResultWorkflowController;
use Illuminate\Support\Facades\Route;

// Setup: KPI library, rating scales, templates, cycles
Route::get('performance/categories', [PerformanceSetupController::class, 'categories']);
Route::post('performance/categories', [PerformanceSetupController::class, 'storeCategory']);
Route::get('performance/kpis', [PerformanceSetupController::class, 'kpis']);
Route::post('performance/kpis', [PerformanceSetupController::class, 'storeKpi']);
Route::put('performance/kpis/{kpi}', [PerformanceSetupController::class, 'updateKpi']);
Route::get('performance/rating-scales', [PerformanceSetupController::class, 'ratingScales']);
Route::post('performance/rating-scales', [PerformanceSetupController::class, 'storeRatingScale']);
Route::get('performance/templates', [PerformanceSetupController::class, 'templates']);
Route::post('performance/templates', [PerformanceSetupController::class, 'storeTemplate']);
Route::post('performance/templates/{template}/clone', [PerformanceSetupController::class, 'cloneTemplate']);
Route::get('performance/cycles', [PerformanceSetupController::class, 'cycles']);
Route::post('performance/cycles', [PerformanceSetupController::class, 'storeCycle']);
Route::post('performance/seed-defaults', [PerformanceSetupController::class, 'seedDefaults']);
Route::post('performance/cycles/{cycle}/calibration/finalize', [ResultWorkflowController::class, 'finalizeCalibration']);
Route::post('performance/cycles/{cycle}/{action}', [PerformanceSetupController::class, 'cycleAction']);

// Assignments and reviews
Route::get('performance/assignments', [AppraisalController::class, 'index']);
Route::post('performance/assignments', [AppraisalController::class, 'store']);
Route::get('performance/assignments/{assignment}', [AppraisalController::class, 'show']);
Route::post('performance/assignments/{assignment}/reviewers/{reviewer}/submit', [AppraisalController::class, 'submitReview']);
Route::post('performance/assignments/{assignment}/reviewers/{reviewer}/return', [AppraisalController::class, 'returnReview']);
Route::post('performance/results/{result}/outcomes', [AppraisalController::class, 'addOutcome']);

// Result workflow: calibration → HR approval → acknowledgment → appeal
Route::post('performance/results/{result}/calibrate', [ResultWorkflowController::class, 'calibrate']);
Route::post('performance/results/{result}/approve', [ResultWorkflowController::class, 'approve']);
Route::post('performance/results/{result}/reject', [ResultWorkflowController::class, 'reject']);
Route::post('performance/results/{result}/acknowledge', [ResultWorkflowController::class, 'acknowledge']);
Route::post('performance/results/{result}/appeal', [ResultWorkflowController::class, 'appeal']);
Route::post('performance/appeals/{appeal}/resolve', [ResultWorkflowController::class, 'resolveAppeal']);

// Performance improvement plans
Route::get('performance/pips', [PipController::class, 'index']);
Route::post('performance/pips', [PipController::class, 'store']);
Route::post('performance/pips/{pip}/activate', [PipController::class, 'activate']);
Route::post('performance/pips/{pip}/close', [PipController::class, 'close']);
Route::put('performance/pip-milestones/{milestone}', [PipController::class, 'updateMilestone']);

// Reports
Route::get('performance/reports/summary', [PerformanceReportController::class, 'summary']);
Route::get('performance/employees/{employee}/trend', [PerformanceReportController::class, 'employeeTrend']);

// Goals / OKRs
Route::get('goals', [GoalController::class, 'index']);
Route::post('goals', [GoalController::class, 'store']);
Route::put('goals/{goal}', [GoalController::class, 'update']);
Route::post('goals/{goal}/check-ins', [GoalController::class, 'checkin']);
