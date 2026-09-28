<?php

use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\AiChatController;
use Modules\AI\Http\Controllers\AiUserLanguagePreferenceSelfController;

Route::middleware(['locale', 'auth:user_api', 'throttle:ai-chat-general'])->prefix('user/v1/ai-chat')->group(function () {
    Route::get('status', [AiChatController::class, 'status']);
    Route::get('usage', [AiChatController::class, 'usage']);

    Route::get('conversations', [AiChatController::class, 'index']);
    Route::post('conversations', [AiChatController::class, 'store']);
    Route::get('conversations/{conversation}', [AiChatController::class, 'show']);
    Route::delete('conversations/{conversation}', [AiChatController::class, 'destroy']);

    // v2.0 requirements doc 17.3: authorized self-service export/erase.
    Route::get('data-export', [AiChatController::class, 'exportData']);
    Route::delete('data', [AiChatController::class, 'eraseData']);
});

// Sending a message triggers a real, paid AI provider call, so it carries
// only the dedicated, stricter ai-chat-send limiter - not stacked on top
// of the generous ai-chat-general one the read/list endpoints above use.
Route::middleware(['locale', 'auth:user_api', 'throttle:ai-chat-send'])->prefix('user/v1/ai-chat')->group(function () {
    Route::post('conversations/{conversation}/messages', [AiChatController::class, 'sendMessage']);

    // v2.0 requirements doc 18.2 (streaming): progressive delivery of
    // the already-verified answer - see AiChatService::streamMessage().
    Route::post('conversations/{conversation}/messages/stream', [AiChatController::class, 'streamMessage']);
});

// Business gap fix: self-service counterpart of the admin-only
// admin/v1/ai-user-language-preferences screen - the user reads/writes
// only their own row (resolved from the auth token, never a route id).
Route::middleware(['locale', 'auth:user_api', 'throttle:ai-chat-general'])->prefix('user/v1/ai-language-preference')->group(function () {
    Route::get('/', [AiUserLanguagePreferenceSelfController::class, 'show']);
    Route::put('/', [AiUserLanguagePreferenceSelfController::class, 'update']);
});
