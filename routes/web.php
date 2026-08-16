<?php

use App\Core\Notifications\Controllers\MailPreviewController;
use App\Modules\Auth\Controllers\DebugEmailVerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Dev/test-only helper: lets testers look up their email verification code
// without checking real mail. Controller aborts 404 outside local/testing.
Route::get('/debug/email-verification-code', [DebugEmailVerificationController::class, 'show'])
    ->name('debug.email-verification-code');

// Dev/test-only: renders every outgoing email template with sample data so
// design changes can be reviewed without sending mail. 404s in production.
Route::get('/debug/emails', [MailPreviewController::class, 'index'])
    ->name('debug.mail-preview');
Route::get('/debug/emails/{email}', [MailPreviewController::class, 'show'])
    ->name('debug.mail-preview.show');
