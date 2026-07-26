<?php

use App\Modules\Auth\Controllers\DebugEmailVerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Dev/test-only helper: lets testers look up their email verification code
// without checking real mail. Controller aborts 404 outside local/testing.
Route::get('/debug/email-verification-code', [DebugEmailVerificationController::class, 'show'])
    ->name('debug.email-verification-code');
