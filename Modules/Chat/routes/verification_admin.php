<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\Admin\Verification\VerificationAuditController;
use Modules\Chat\Http\Controllers\Admin\Verification\VerificationBadgeController;
use Modules\Chat\Http\Controllers\Admin\Verification\VerificationDocumentTypeController;
use Modules\Chat\Http\Controllers\Admin\Verification\VerificationRequestController;
use Modules\Chat\Http\Controllers\Admin\Verification\VerificationSettingController;

/*
 * Account verification in the dashboard (docs/verification_tasks.md) — `/api/admin/v1/chat-verification/*`
 * (guard admin_api). Every controller checks its permission through EnsureVerificationPermission, which
 * also writes a refused attempt to the audit log.
 */
Route::prefix('chat-verification')->group(function () {
    Route::get('settings', [VerificationSettingController::class, 'show']);
    Route::put('settings', [VerificationSettingController::class, 'update']);

    Route::get('document-types', [VerificationDocumentTypeController::class, 'index']);
    Route::post('document-types', [VerificationDocumentTypeController::class, 'store']);
    Route::put('document-types/{documentType}', [VerificationDocumentTypeController::class, 'update']);
    Route::patch('document-types/{documentType}/status', [VerificationDocumentTypeController::class, 'status']);
    Route::delete('document-types/{documentType}', [VerificationDocumentTypeController::class, 'destroy']);

    // Requests: the list, one request, its files, and the decisions (review ≠ approve).
    Route::get('requests', [VerificationRequestController::class, 'index']);
    Route::get('requests/{verificationRequest}', [VerificationRequestController::class, 'show']);
    Route::get('requests/{verificationRequest}/documents/{document}/{side}', [VerificationRequestController::class, 'file'])->whereNumber('document')->whereIn('side', ['front', 'back']);
    Route::post('requests/{verificationRequest}/start-review', [VerificationRequestController::class, 'startReview']);
    Route::post('requests/{verificationRequest}/documents/{document}/review', [VerificationRequestController::class, 'reviewDocument'])->whereNumber('document');
    Route::post('requests/{verificationRequest}/identity-check', [VerificationRequestController::class, 'checkIdentity']);
    Route::post('requests/{verificationRequest}/request-completion', [VerificationRequestController::class, 'requestCompletion']);
    Route::post('requests/{verificationRequest}/approve', [VerificationRequestController::class, 'approve']);
    Route::post('requests/{verificationRequest}/reject', [VerificationRequestController::class, 'reject']);
    Route::post('requests/{verificationRequest}/deadline', [VerificationRequestController::class, 'extendDeadline']);
    Route::post('requests/{verificationRequest}/deadline/pause', [VerificationRequestController::class, 'pauseDeadline']);
    Route::post('requests/{verificationRequest}/deadline/resume', [VerificationRequestController::class, 'resumeDeadline']);
    Route::post('requests/{verificationRequest}/resume', [VerificationRequestController::class, 'resume']);
    Route::post('requests/{verificationRequest}/expire', [VerificationRequestController::class, 'expire']);
    Route::post('requests/{verificationRequest}/cancel', [VerificationRequestController::class, 'cancel']);
    Route::post('requests/{verificationRequest}/early-activate', [VerificationRequestController::class, 'earlyActivate']);
    Route::post('requests/{verificationRequest}/activation-timing', [VerificationRequestController::class, 'setTiming']);

    // Badges: every account's badge and its changes (with the reason the account reads).
    Route::get('badges', [VerificationBadgeController::class, 'index']);
    Route::get('badges/{user}', [VerificationBadgeController::class, 'show'])->whereNumber('user');
    Route::post('badges/{user}/change', [VerificationBadgeController::class, 'change'])->whereNumber('user');
    Route::post('badges/{user}/suspend', [VerificationBadgeController::class, 'suspend'])->whereNumber('user');
    Route::post('badges/{user}/reactivate', [VerificationBadgeController::class, 'reactivate'])->whereNumber('user');
    Route::post('badges/{user}/withdraw', [VerificationBadgeController::class, 'withdraw'])->whereNumber('user');

    Route::get('audit', [VerificationAuditController::class, 'index']);
    Route::get('notifications', [VerificationAuditController::class, 'notifications']);
});
