<?php

use Illuminate\Support\Facades\Route;
use Modules\Wallet\Http\Middleware\RequiresWalletPin;
use Modules\AI\Http\Controllers\AiChatController;
use Modules\AI\Http\Controllers\AiConversationFileController;
use Modules\AI\Http\Controllers\AiRealtimeController;
use Modules\AI\Http\Controllers\AiSiteProjectController;
use Modules\AI\Http\Controllers\AiSiteHostingController;
use Modules\AI\Http\Controllers\AiUserSubscriptionController;
use Modules\AI\Http\Controllers\AiUserLanguagePreferenceSelfController;
use Modules\AI\Http\Controllers\AiFileUploadController;

Route::middleware(['locale', 'auth:user_api', 'throttle:ai-chat-general'])->prefix('user/v1/ai-chat')->group(function () {
    Route::get('status', [AiChatController::class, 'status']);
    Route::get('usage', [AiChatController::class, 'usage']);

    Route::get('conversations', [AiChatController::class, 'index']);
    Route::post('conversations', [AiChatController::class, 'store']);
    Route::get('conversations/{conversation}', [AiChatController::class, 'show']);
    Route::delete('conversations/{conversation}', [AiChatController::class, 'destroy']);

    // Phase 10 (doc S27): the explicit conversation<->file relationship
    // (attach an already-uploaded file to this conversation's context /
    // list what's attached / detach - never a file upload itself, and
    // never deletes the underlying AiFile - see AiConversationFileScope).
    Route::get('conversations/{conversation}/files', [AiConversationFileController::class, 'index']);
    Route::post('conversations/{conversation}/files', [AiConversationFileController::class, 'store']);
    Route::delete('conversations/{conversation}/files/{file}', [AiConversationFileController::class, 'destroy']);

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

// Acceptance criteria doc S14: standalone customer-facing File Engine
// API. Reads/status share the general throttle tier (cheap lookups);
// upload shares the stricter ai-chat-send tier because it triggers real
// background processing work, same reasoning as sendMessage() above.
Route::middleware(['locale', 'auth:user_api', 'throttle:ai-chat-general'])->prefix('user/v1/ai-files')->group(function () {
    Route::get('{file}', [AiFileUploadController::class, 'show']);
    Route::get('{file}/status', [AiFileUploadController::class, 'status']);
    Route::delete('{file}', [AiFileUploadController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:user_api', 'throttle:ai-chat-send'])->prefix('user/v1/ai-files')->group(function () {
    Route::post('/', [AiFileUploadController::class, 'store']);
});

// Phase 7 (realtime voice): minting a session credential triggers a real
// provider call exactly like sending a chat message does, so it shares
// the same dedicated ai-chat-send limiter rather than the general one.
Route::middleware(['locale', 'auth:user_api', 'throttle:ai-chat-send'])->prefix('user/v1/ai-realtime')->group(function () {
    Route::post('session', [AiRealtimeController::class, 'createSession']);
    Route::post('session/{session}/end', [AiRealtimeController::class, 'endSession']);
});

// Business gap fix: self-service counterpart of the admin-only
// admin/v1/ai-user-language-preferences screen - the user reads/writes
// only their own row (resolved from the auth token, never a route id).
Route::middleware(['locale', 'auth:user_api', 'throttle:ai-chat-general'])->prefix('user/v1/ai-language-preference')->group(function () {
    Route::get('/', [AiUserLanguagePreferenceSelfController::class, 'show']);
    Route::put('/', [AiUserLanguagePreferenceSelfController::class, 'update']);
});

// Professional AI subscription system linked to the wallet (2026-09-29):
// browsing plans and reading the current subscription/payment history are
// read-only, so they share the general limiter; subscribing and changing
// plan move real money and require the wallet PIN
// (Modules\Wallet\Http\Middleware\RequiresWalletPin) exactly like every
// other money-moving endpoint in the app - "PIN إجباري على... دفع أي خدمة
// من المحفظة" (docs/wallet-structure.md). 'country' is required so
// currentCountry() (AiSubscriptionBillingService::walletFor()) resolves.
Route::middleware(['locale', 'country', 'auth:user_api', 'throttle:ai-chat-general'])->prefix('user/v1/ai-subscription')->group(function () {
    Route::get('plans', [AiUserSubscriptionController::class, 'plans']);
    Route::get('/', [AiUserSubscriptionController::class, 'current']);
    Route::get('payments', [AiUserSubscriptionController::class, 'payments']);
    Route::put('auto-renew', [AiUserSubscriptionController::class, 'updateAutoRenew']);
});

Route::middleware(['locale', 'country', 'auth:user_api', 'throttle:ai-chat-send', RequiresWalletPin::class])->prefix('user/v1/ai-subscription')->group(function () {
    Route::post('subscribe', [AiUserSubscriptionController::class, 'subscribe']);
    Route::post('change-plan', [AiUserSubscriptionController::class, 'changePlan']);
});

// Website builder. Reading/listing/editing use the general limiter; anything
// that spends AI quota or money is stricter, and a standalone purchase also
// needs the wallet PIN + a resolved country (price is per country).
Route::middleware(['locale', 'country', 'auth:user_api', 'throttle:ai-chat-general'])->prefix('user/v1/ai-sites')->group(function () {
    Route::get('offers', [AiSiteProjectController::class, 'offers']);
    Route::get('/', [AiSiteProjectController::class, 'index']);
    Route::get('{project}', [AiSiteProjectController::class, 'show'])->whereNumber('project');
    Route::get('{project}/download', [AiSiteProjectController::class, 'download'])->whereNumber('project');
    Route::delete('{project}', [AiSiteProjectController::class, 'destroy'])->whereNumber('project');
});

Route::middleware(['locale', 'country', 'auth:user_api', 'throttle:ai-chat-send'])->prefix('user/v1/ai-sites')->group(function () {
    Route::post('/', [AiSiteProjectController::class, 'store']);
    Route::post('{project}/edit', [AiSiteProjectController::class, 'edit'])->whereNumber('project');
    Route::post('{project}/retry', [AiSiteProjectController::class, 'retry'])->whereNumber('project');
    Route::post('{project}/versions/{number}/restore', [AiSiteProjectController::class, 'restore'])->whereNumber(['project', 'number']);
});

Route::middleware(['locale', 'country', 'auth:user_api', 'throttle:ai-chat-send', RequiresWalletPin::class])->prefix('user/v1/ai-sites')->group(function () {
    Route::post('purchase', [AiSiteProjectController::class, 'purchase']);
});

// Site hosting (sub-domain, monthly/yearly). Money-moving calls need the wallet PIN.
Route::middleware(['locale', 'country', 'auth:user_api', 'throttle:ai-chat-general'])->prefix('user/v1/ai-sites')->group(function () {
    Route::get('hosting/plans', [AiSiteHostingController::class, 'plans']);
    Route::get('hosting/check', [AiSiteHostingController::class, 'check']);
    Route::get('{project}/hosting', [AiSiteHostingController::class, 'show'])->whereNumber('project');
    Route::put('{project}/hosting/auto-renew', [AiSiteHostingController::class, 'autoRenew'])->whereNumber('project');
    Route::post('{project}/hosting/publish', [AiSiteHostingController::class, 'publish'])->whereNumber('project');
});

Route::middleware(['locale', 'country', 'auth:user_api', 'throttle:ai-chat-send', RequiresWalletPin::class])->prefix('user/v1/ai-sites')->group(function () {
    Route::post('{project}/hosting', [AiSiteHostingController::class, 'subscribe'])->whereNumber('project');
    Route::post('{project}/hosting/renew', [AiSiteHostingController::class, 'renew'])->whereNumber('project');
});
