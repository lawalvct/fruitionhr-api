<?php

use App\Modules\Recruitment\Controllers\PublicCompanyLogoController;
use App\Modules\Recruitment\Controllers\PublicVacancyController;
use Illuminate\Support\Facades\Route;

Route::get('careers', [PublicVacancyController::class, 'index'])
    ->middleware('throttle:120,1')
    ->name('v1.careers.index');
Route::get('careers/{slug}', [PublicVacancyController::class, 'show'])
    ->where('slug', '[A-Za-z0-9-]+')
    ->middleware('throttle:120,1')
    ->name('v1.careers.show');
Route::post('careers/{slug}/apply', [PublicVacancyController::class, 'apply'])
    ->where('slug', '[A-Za-z0-9-]+')
    ->middleware('throttle:5,1')
    ->name('v1.careers.apply');

// Employer logo for the careers site. Only served while the company is
// actually advertising — see the controller.
Route::get('careers/companies/{slug}/logo', PublicCompanyLogoController::class)
    ->where('slug', '[A-Za-z0-9-]+')
    ->middleware('throttle:240,1')
    ->name('v1.careers.company-logo');
