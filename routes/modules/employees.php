<?php

use App\Modules\Employee\Controllers\EmployeeBankAccountController;
use App\Modules\Employee\Controllers\EmployeeContactController;
use App\Modules\Employee\Controllers\EmployeeController;
use Illuminate\Support\Facades\Route;

Route::apiResource('employees', EmployeeController::class);
Route::post('employees/{employee}/assignments', [EmployeeController::class, 'assign']);

Route::post('employees/{employee}/contacts', [EmployeeContactController::class, 'store']);
Route::put('employees/{employee}/contacts/{contact}', [EmployeeContactController::class, 'update']);
Route::delete('employees/{employee}/contacts/{contact}', [EmployeeContactController::class, 'destroy']);

Route::post('employees/{employee}/bank-accounts', [EmployeeBankAccountController::class, 'store']);
Route::put('employees/{employee}/bank-accounts/{bankAccount}', [EmployeeBankAccountController::class, 'update']);
Route::delete('employees/{employee}/bank-accounts/{bankAccount}', [EmployeeBankAccountController::class, 'destroy']);
