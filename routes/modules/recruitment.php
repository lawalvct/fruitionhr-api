<?php

use App\Modules\Recruitment\Controllers\ApplicationController;
use App\Modules\Recruitment\Controllers\RequisitionController;
use App\Modules\Recruitment\Controllers\VacancyController;
use Illuminate\Support\Facades\Route;

Route::get('recruitment/requisitions', [RequisitionController::class, 'index']);
Route::post('recruitment/requisitions', [RequisitionController::class, 'store']);
Route::put('recruitment/requisitions/{requisition}', [RequisitionController::class, 'update']);
Route::delete('recruitment/requisitions/{requisition}', [RequisitionController::class, 'destroy']);
Route::post('recruitment/requisitions/{requisition}/submit', [RequisitionController::class, 'submit']);

Route::get('recruitment/vacancies', [VacancyController::class, 'index']);
Route::post('recruitment/vacancies', [VacancyController::class, 'store']);
Route::put('recruitment/vacancies/{vacancy}', [VacancyController::class, 'update']);
Route::post('recruitment/vacancies/{vacancy}/open', [VacancyController::class, 'open']);
Route::post('recruitment/vacancies/{vacancy}/close', [VacancyController::class, 'close']);

Route::get('recruitment/applications', [ApplicationController::class, 'index']);
Route::post('recruitment/applications', [ApplicationController::class, 'store']);
Route::get('recruitment/applications/{application}', [ApplicationController::class, 'show']);
Route::post('recruitment/applications/{application}/move', [ApplicationController::class, 'move']);
Route::post('recruitment/applications/{application}/interviews', [ApplicationController::class, 'scheduleInterview']);
Route::post('recruitment/interviews/{interview}/complete', [ApplicationController::class, 'completeInterview']);
Route::post('recruitment/applications/{application}/offers', [ApplicationController::class, 'createOffer']);
Route::post('recruitment/applications/{application}/offers/{offer}/{action}', [ApplicationController::class, 'offerAction']);
Route::post('recruitment/applications/{application}/onboarding-tasks', [ApplicationController::class, 'createTask']);
Route::post('recruitment/applications/{application}/onboarding-tasks/{task}/complete', [ApplicationController::class, 'completeTask']);
Route::post('recruitment/applications/{application}/hire', [ApplicationController::class, 'hire']);
