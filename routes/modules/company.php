<?php

use App\Modules\Company\Controllers\BranchController;
use App\Modules\Company\Controllers\CompanyProfileController;
use App\Modules\Company\Controllers\DepartmentController;
use App\Modules\Company\Controllers\EmploymentTypeController;
use App\Modules\Company\Controllers\HolidayCalendarController;
use App\Modules\Company\Controllers\JobGradeController;
use App\Modules\Company\Controllers\PositionController;
use Illuminate\Support\Facades\Route;

Route::get('company/logo', [CompanyProfileController::class, 'showLogo'])->name('company.logo.show');
Route::post('company/logo', [CompanyProfileController::class, 'uploadLogo']);
Route::delete('company/logo', [CompanyProfileController::class, 'deleteLogo']);

Route::apiResource('branches', BranchController::class);
Route::apiResource('departments', DepartmentController::class);
Route::apiResource('positions', PositionController::class);
Route::apiResource('job-grades', JobGradeController::class)->parameters([
    'job-grades' => 'jobGrade',
]);
Route::apiResource('employment-types', EmploymentTypeController::class)->parameters([
    'employment-types' => 'employmentType',
]);
Route::apiResource('holiday-calendars', HolidayCalendarController::class)->parameters([
    'holiday-calendars' => 'holidayCalendar',
]);
