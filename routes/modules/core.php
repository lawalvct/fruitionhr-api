<?php

use App\Core\Documents\Controllers\DocumentController;
use App\Core\Workflow\Controllers\ApprovalController;
use Illuminate\Support\Facades\Route;

// Approvals inbox (workflow engine)
Route::get('/approvals', [ApprovalController::class, 'index'])->name('v1.approvals.index');
Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('v1.approvals.approve');
Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('v1.approvals.reject');
Route::post('/approvals/{approval}/return', [ApprovalController::class, 'return'])->name('v1.approvals.return');

// Documents (polymorphic attachments)
Route::get('/documents', [DocumentController::class, 'index'])->name('v1.documents.index');
Route::post('/documents', [DocumentController::class, 'store'])->name('v1.documents.store');
Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('v1.documents.download');
Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('v1.documents.destroy');

// Notifications are declared in routes/api.php: they belong to the user, not
// to a company, and platform administrators have no tenant to be scoped to.
