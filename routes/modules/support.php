<?php

use App\Modules\Support\Controllers\SupportTicketController;
use Illuminate\Support\Facades\Route;

Route::get('support/tickets', [SupportTicketController::class, 'index'])->name('v1.support.index');
Route::post('support/tickets', [SupportTicketController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('v1.support.store');
Route::get('support/tickets/{ticket}', [SupportTicketController::class, 'show'])
    ->whereNumber('ticket')
    ->name('v1.support.show');
Route::post('support/tickets/{ticket}/messages', [SupportTicketController::class, 'reply'])
    ->whereNumber('ticket')
    ->middleware('throttle:30,1')
    ->name('v1.support.reply');
Route::post('support/tickets/{ticket}/close', [SupportTicketController::class, 'close'])
    ->whereNumber('ticket')
    ->name('v1.support.close');
