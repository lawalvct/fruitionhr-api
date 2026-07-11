<?php

use App\Modules\Payroll\Controllers\EmployeeSalaryController;
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
