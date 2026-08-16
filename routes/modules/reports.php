<?php

use App\Modules\Reports\Controllers\ReportController;
use App\Modules\Reports\Controllers\ReportCsvExportController;
use App\Modules\Reports\Controllers\ReportExcelExportController;
use App\Modules\Reports\Controllers\ReportPdfExportController;
use App\Modules\Reports\Services\ReportAnalysisService;
use Illuminate\Support\Facades\Route;

Route::get('reports/overview', [ReportController::class, 'overview'])
    ->name('v1.reports.overview');

Route::get('reports/{module}/analysis', [ReportController::class, 'analysis'])
    ->whereIn('module', ReportAnalysisService::MODULES)
    ->name('v1.reports.analysis');

Route::get('reports/{module}/export.csv', ReportCsvExportController::class)
    ->whereIn('module', ReportAnalysisService::MODULES)
    ->name('v1.reports.export.csv');

Route::get('reports/{module}/export.xlsx', ReportExcelExportController::class)
    ->whereIn('module', ReportAnalysisService::MODULES)
    ->name('v1.reports.export.xlsx');

Route::get('reports/{module}/export.pdf', ReportPdfExportController::class)
    ->whereIn('module', ReportAnalysisService::MODULES)
    ->name('v1.reports.export.pdf');
