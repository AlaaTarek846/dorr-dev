<?php

use Illuminate\Support\Facades\Route;
use Modules\Wallet\Http\Middleware\RequiresWalletPin;
use Modules\AI\Http\Controllers\AiChatController;
use Modules\AI\Http\Controllers\AiRealtimeController;
use Modules\AI\Http\Controllers\AiUserSubscriptionController;

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

// Phase 7 (realtime voice): minting a session credential triggers a real
// provider call exactly like sending a chat message does, so it shares
// the same dedicated ai-chat-send limiter rather than the general one.
Route::middleware(['locale', 'auth:provider_api', 'throttle:ai-chat-send'])->prefix('provider/v1/ai-realtime')->group(function () {
    Route::post('session', [AiRealtimeController::class, 'createSession']);
    Route::post('session/{session}/end', [AiRealtimeController::class, 'endSession']);
});

// Professional AI subscription system linked to the wallet (2026-09-29):
// browsing plans and reading the current subscription/payment history are
// read-only, so they share the general limiter; subscribing and changing
// plan move real money and require the wallet PIN
// (Modules\Wallet\Http\Middleware\RequiresWalletPin) exactly like every
// other money-moving endpoint in the app - "PIN إجباري على... دفع أي خدمة
// من المحفظة" (docs/wallet-structure.md). 'country' is required so
// currentCountry() (AiSubscriptionBillingService::walletFor()) resolves.
Route::middleware(['locale', 'country', 'auth:provider_api', 'throttle:ai-chat-general'])->prefix('provider/v1/ai-subscription')->group(function () {
    Route::get('plans', [AiUserSubscriptionController::class, 'plans']);
    Route::get('/', [AiUserSubscriptionController::class, 'current']);
    Route::get('payments', [AiUserSubscriptionController::class, 'payments']);
    Route::put('auto-renew', [AiUserSubscriptionController::class, 'updateAutoRenew']);
});

Route::middleware(['locale', 'country', 'auth:provider_api', 'throttle:ai-chat-send', RequiresWalletPin::class])->prefix('provider/v1/ai-subscription')->group(function () {
    Route::post('subscribe', [AiUserSubscriptionController::class, 'subscribe']);
    Route::post('change-plan', [AiUserSubscriptionController::class, 'changePlan']);
});
