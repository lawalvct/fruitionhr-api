<?php

use App\Modules\Payroll\Controllers\EmployeeSalaryController;
use App\Modules\Payroll\Controllers\LoanController;
use App\Modules\Payroll\Controllers\OvertimeController;
use App\Modules\Payroll\Controllers\PayrollOutputController;
use App\Modules\Payroll\Controllers\PayrollReportController;
use App\Modules\Payroll\Controllers\PayrollRunController;
use App\Modules\Payroll\Controllers\PayrollSettingsController;
use App\Modules\Payroll\Controllers\SalaryComponentController;
use App\Modules\Payroll\Controllers\SalaryFormulaController;
use App\Modules\Payroll\Controllers\SalaryStructureController;
use Illuminate\Support\Facades\Route;

// Salary components
Route::get('payroll-settings', [PayrollSettingsController::class, 'show'])->name('v1.payroll-settings.show');
Route::post('payroll-settings/advanced-salary-formulas/enable', [PayrollSettingsController::class, 'enable'])->name('v1.payroll-settings.formulas.enable');
Route::post('payroll-settings/advanced-salary-formulas/disable', [PayrollSettingsController::class, 'disable'])->name('v1.payroll-settings.formulas.disable');
Route::get('salary-formulas/catalog', [SalaryFormulaController::class, 'catalog'])->name('v1.salary-formulas.catalog');
Route::get('salary-components', [SalaryComponentController::class, 'index'])->name('v1.salary-components.index');
Route::post('salary-components', [SalaryComponentController::class, 'store'])->name('v1.salary-components.store');
Route::put('salary-components/{salaryComponent}', [SalaryComponentController::class, 'update'])->name('v1.salary-components.update');
Route::delete('salary-components/{salaryComponent}', [SalaryComponentController::class, 'destroy'])->name('v1.salary-components.destroy');
Route::get('salary-components/{salaryComponent}/formula', [SalaryFormulaController::class, 'show'])->name('v1.salary-components.formula.show');
Route::put('salary-components/{salaryComponent}/formula/draft', [SalaryFormulaController::class, 'saveDraft'])->name('v1.salary-components.formula.draft');
Route::post('salary-components/{salaryComponent}/formula/evaluate', [SalaryFormulaController::class, 'evaluate'])->name('v1.salary-components.formula.evaluate');
Route::post('salary-components/{salaryComponent}/formula/publish', [SalaryFormulaController::class, 'publish'])->name('v1.salary-components.formula.publish');

// Salary structures
Route::get('salary-structures', [SalaryStructureController::class, 'index'])->name('v1.salary-structures.index');
Route::post('salary-structures', [SalaryStructureController::class, 'store'])->name('v1.salary-structures.store');
Route::put('salary-structures/{salaryStructure}', [SalaryStructureController::class, 'update'])->name('v1.salary-structures.update');
Route::delete('salary-structures/{salaryStructure}', [SalaryStructureController::class, 'destroy'])->name('v1.salary-structures.destroy');

// Employee salary (history + resolved breakdown)
Route::get('employees/{employee}/salary', [EmployeeSalaryController::class, 'show'])->name('v1.employees.salary.show');
Route::post('employees/{employee}/salary', [EmployeeSalaryController::class, 'store'])->name('v1.employees.salary.store');
Route::get('employees/{employee}/salary-history', [EmployeeSalaryController::class, 'history'])->name('v1.employees.salary.history');
Route::post('employees/{employee}/salary/increase', [EmployeeSalaryController::class, 'increase'])->name('v1.employees.salary.increase');

// Payroll runs
Route::get('payroll/preflight', [PayrollRunController::class, 'preflight'])->name('v1.payroll.preflight');
Route::get('payroll-runs', [PayrollRunController::class, 'index'])->name('v1.payroll-runs.index');
Route::post('payroll-runs', [PayrollRunController::class, 'store'])->name('v1.payroll-runs.store');
Route::get('payroll-runs/{payrollRun}', [PayrollRunController::class, 'show'])->name('v1.payroll-runs.show');
Route::post('payroll-runs/{payrollRun}/retry-calculation', [PayrollRunController::class, 'retryCalculation'])->name('v1.payroll-runs.retry-calculation');
Route::get('payroll-runs/{payrollRun}/employees/{runEmployee}', [PayrollRunController::class, 'employeeDetail'])->name('v1.payroll-runs.employee');
Route::post('payroll-runs/{payrollRun}/submit', [PayrollRunController::class, 'submit'])->name('v1.payroll-runs.submit');
Route::post('payroll-runs/{payrollRun}/lock', [PayrollRunController::class, 'lock'])->name('v1.payroll-runs.lock');
Route::post('payroll-runs/{payrollRun}/reverse', [PayrollRunController::class, 'reverse'])->name('v1.payroll-runs.reverse');

// Payroll outputs (available once approved/locked)
Route::get('payroll-runs/{payrollRun}/employees/{runEmployee}/payslip', [PayrollOutputController::class, 'payslip'])->name('v1.payroll-runs.payslip');
Route::get('payroll-runs/{payrollRun}/bank-schedule', [PayrollOutputController::class, 'bankSchedule'])->name('v1.payroll-runs.bank-schedule');
Route::get('payroll-runs/{payrollRun}/statutory-report', [PayrollOutputController::class, 'statutoryReport'])->name('v1.payroll-runs.statutory-report');

// Overtime payments (approved via the 'overtime' workflow; paid in payroll or off-cycle)
Route::get('overtime/attendance-candidates', [OvertimeController::class, 'attendanceCandidates'])->name('v1.overtime.attendance-candidates');
Route::post('overtime/from-attendance', [OvertimeController::class, 'fromAttendance'])->name('v1.overtime.from-attendance');
Route::get('overtime', [OvertimeController::class, 'index'])->name('v1.overtime.index');
Route::post('overtime', [OvertimeController::class, 'store'])->name('v1.overtime.store');
Route::get('overtime/{overtime}', [OvertimeController::class, 'show'])->name('v1.overtime.show');
Route::put('overtime/{overtime}', [OvertimeController::class, 'update'])->name('v1.overtime.update');
Route::delete('overtime/{overtime}', [OvertimeController::class, 'destroy'])->name('v1.overtime.destroy');
Route::post('overtime/{overtime}/submit', [OvertimeController::class, 'submit'])->name('v1.overtime.submit');
Route::post('overtime/{overtime}/pay', [OvertimeController::class, 'pay'])->name('v1.overtime.pay');

// Staff loans & salary advances (approved via the 'loan' workflow; recovered from payroll)
Route::get('loans', [LoanController::class, 'index'])->name('v1.loans.index');
Route::post('loans', [LoanController::class, 'store'])->name('v1.loans.store');
Route::get('loans/{loan}', [LoanController::class, 'show'])->name('v1.loans.show');
Route::put('loans/{loan}', [LoanController::class, 'update'])->name('v1.loans.update');
Route::delete('loans/{loan}', [LoanController::class, 'destroy'])->name('v1.loans.destroy');
Route::post('loans/{loan}/submit', [LoanController::class, 'submit'])->name('v1.loans.submit');
Route::post('loans/{loan}/plan-deduction', [LoanController::class, 'planDeduction'])->name('v1.loans.plan-deduction');
Route::post('loans/{loan}/clear-deduction', [LoanController::class, 'clearDeduction'])->name('v1.loans.clear-deduction');
Route::post('loans/{loan}/installment', [LoanController::class, 'setInstallment'])->name('v1.loans.installment');
Route::get('loans/{loan}/repayments', [LoanController::class, 'repayments'])->name('v1.loans.repayments');

// Advanced payroll reports
Route::get('payroll-runs/{payrollRun}/variance', [PayrollReportController::class, 'variance'])->name('v1.payroll-runs.variance');
Route::get('payroll-runs/{payrollRun}/journal', [PayrollReportController::class, 'journal'])->name('v1.payroll-runs.journal');
Route::get('payroll-runs/{payrollRun}/journal.xlsx', [PayrollReportController::class, 'journalExport'])->name('v1.payroll-runs.journal-export');
