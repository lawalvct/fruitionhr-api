<?php

use App\Modules\Payroll\Controllers\EmployeeSalaryController;
use App\Modules\Payroll\Controllers\PayrollOutputController;
use App\Modules\Payroll\Controllers\PayrollReportController;
use App\Modules\Payroll\Controllers\PayrollRunController;
use App\Modules\Payroll\Controllers\SalaryComponentController;
use App\Modules\Payroll\Controllers\SalaryStructureController;
use Illuminate\Support\Facades\Route;

// Salary components
Route::get('salary-components', [SalaryComponentController::class, 'index'])->name('v1.salary-components.index');
Route::post('salary-components', [SalaryComponentController::class, 'store'])->name('v1.salary-components.store');
Route::put('salary-components/{salaryComponent}', [SalaryComponentController::class, 'update'])->name('v1.salary-components.update');
Route::delete('salary-components/{salaryComponent}', [SalaryComponentController::class, 'destroy'])->name('v1.salary-components.destroy');

// Salary structures
Route::get('salary-structures', [SalaryStructureController::class, 'index'])->name('v1.salary-structures.index');
Route::post('salary-structures', [SalaryStructureController::class, 'store'])->name('v1.salary-structures.store');
Route::put('salary-structures/{salaryStructure}', [SalaryStructureController::class, 'update'])->name('v1.salary-structures.update');
Route::delete('salary-structures/{salaryStructure}', [SalaryStructureController::class, 'destroy'])->name('v1.salary-structures.destroy');

// Employee salary (history + resolved breakdown)
Route::get('employees/{employee}/salary', [EmployeeSalaryController::class, 'show'])->name('v1.employees.salary.show');
Route::post('employees/{employee}/salary', [EmployeeSalaryController::class, 'store'])->name('v1.employees.salary.store');

// Payroll runs
Route::get('payroll/preflight', [PayrollRunController::class, 'preflight'])->name('v1.payroll.preflight');
Route::get('payroll-runs', [PayrollRunController::class, 'index'])->name('v1.payroll-runs.index');
Route::post('payroll-runs', [PayrollRunController::class, 'store'])->name('v1.payroll-runs.store');
Route::get('payroll-runs/{payrollRun}', [PayrollRunController::class, 'show'])->name('v1.payroll-runs.show');
Route::get('payroll-runs/{payrollRun}/employees/{runEmployee}', [PayrollRunController::class, 'employeeDetail'])->name('v1.payroll-runs.employee');
Route::post('payroll-runs/{payrollRun}/submit', [PayrollRunController::class, 'submit'])->name('v1.payroll-runs.submit');
Route::post('payroll-runs/{payrollRun}/lock', [PayrollRunController::class, 'lock'])->name('v1.payroll-runs.lock');
Route::post('payroll-runs/{payrollRun}/reverse', [PayrollRunController::class, 'reverse'])->name('v1.payroll-runs.reverse');

// Payroll outputs (available once approved/locked)
Route::get('payroll-runs/{payrollRun}/employees/{runEmployee}/payslip', [PayrollOutputController::class, 'payslip'])->name('v1.payroll-runs.payslip');
Route::get('payroll-runs/{payrollRun}/bank-schedule', [PayrollOutputController::class, 'bankSchedule'])->name('v1.payroll-runs.bank-schedule');
Route::get('payroll-runs/{payrollRun}/statutory-report', [PayrollOutputController::class, 'statutoryReport'])->name('v1.payroll-runs.statutory-report');

// Advanced payroll reports
Route::get('payroll-runs/{payrollRun}/variance', [PayrollReportController::class, 'variance'])->name('v1.payroll-runs.variance');
Route::get('payroll-runs/{payrollRun}/journal', [PayrollReportController::class, 'journal'])->name('v1.payroll-runs.journal');
Route::get('payroll-runs/{payrollRun}/journal.xlsx', [PayrollReportController::class, 'journalExport'])->name('v1.payroll-runs.journal-export');
