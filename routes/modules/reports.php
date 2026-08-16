<?php

use App\Modules\Reports\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('reports/overview', [ReportController::class, 'overview'])
    ->name('v1.reports.overview');
