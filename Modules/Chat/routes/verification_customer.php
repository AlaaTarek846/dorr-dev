<?php

use Illuminate\Support\Facades\Route;
use Modules\Chat\Http\Controllers\General\Verification\VerificationOptionsController;
use Modules\Chat\Http\Controllers\General\Verification\VerificationRequestController;

/*
 * Account verification for the account itself (docs/verification_tasks.md) — inside the chat's
 * `chat` prefix, so `/api/mobile/v1/chat/verification/*` (guard user_api).
 */
Route::prefix('verification')->group(function () {
    // Which tracks are open where this person is, and what each one asks them to upload.
    Route::get('options', VerificationOptionsController::class);

    // The account's request: send it (multipart, with its documents), follow it, complete it, cancel it.
    // My badge: status, type, since when, until when.
    Route::get('badge', [VerificationRequestController::class, 'badge']);
    Route::get('request', [VerificationRequestController::class, 'show']);
    Route::post('request', [VerificationRequestController::class, 'store'])->middleware('throttle:10,1,chat-verification-request');
    Route::post('request/{uuid}/complete', [VerificationRequestController::class, 'complete'])->middleware('throttle:20,1,chat-verification-request');
    Route::post('request/{uuid}/cancel', [VerificationRequestController::class, 'cancel']);
    Route::get('request/{uuid}/documents/{document}/{side}', [VerificationRequestController::class, 'file'])->whereNumber('document')->whereIn('side', ['front', 'back']);
});
