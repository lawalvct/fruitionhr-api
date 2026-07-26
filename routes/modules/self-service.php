<?php

use App\Modules\SelfService\Controllers\SelfServiceController;
use Illuminate\Support\Facades\Route;

Route::prefix('self')->name('v1.self.')->group(function (): void {
    Route::get('profile', [SelfServiceController::class, 'profile'])->name('profile.show');
    Route::get('profile-update-requests', [SelfServiceController::class, 'profileUpdateRequests'])->name('profile-update-requests.index');
    Route::post('profile-update-requests', [SelfServiceController::class, 'storeProfileUpdateRequest'])->name('profile-update-requests.store');

    Route::get('leave-requests', [SelfServiceController::class, 'leaveRequests'])->name('leave-requests.index');
    Route::post('leave-requests', [SelfServiceController::class, 'storeLeaveRequest'])->name('leave-requests.store');
    Route::get('leave-types', [SelfServiceController::class, 'leaveTypes'])->name('leave-types.index');
    Route::get('leave-balances', [SelfServiceController::class, 'leaveBalances'])->name('leave-balances.index');

    Route::get('attendance', [SelfServiceController::class, 'attendance'])->name('attendance.show');
    Route::get('attendance/today', [SelfServiceController::class, 'todayAttendance'])->name('attendance.today');
    Route::post('attendance/clock-in', [SelfServiceController::class, 'clockIn'])->name('attendance.clock-in');
    Route::post('attendance/clock-out', [SelfServiceController::class, 'clockOut'])->name('attendance.clock-out');

    Route::get('payslips', [SelfServiceController::class, 'payslips'])->name('payslips.index');
    Route::get('payslips/{payslip}/download', [SelfServiceController::class, 'downloadPayslip'])->name('payslips.download');
});
