<?php

use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\AiChatController;

// Same AiChatController as routes/user.php - AI chat is shared between the
// User and Provider dashboards. ai_conversations.owner is polymorphic, see
// AIServiceProvider::boot().
Route::middleware(['locale', 'auth:provider_api', 'throttle:ai-chat-general'])->prefix('provider/v1/ai-chat')->group(function () {
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
Route::middleware(['locale', 'auth:provider_api', 'throttle:ai-chat-send'])->prefix('provider/v1/ai-chat')->group(function () {
    Route::post('conversations/{conversation}/messages', [AiChatController::class, 'sendMessage']);

    // v2.0 requirements doc 18.2 (streaming): progressive delivery of
    // the already-verified answer - see AiChatService::streamMessage().
    Route::post('conversations/{conversation}/messages/stream', [AiChatController::class, 'streamMessage']);
});
