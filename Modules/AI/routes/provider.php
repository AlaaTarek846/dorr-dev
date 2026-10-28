<?php

use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\Ar\AiArController;
use Modules\AI\Http\Controllers\Ar\AiArOrderController;
use Modules\AI\Http\Controllers\World\AiWorldController;
use Modules\AI\Http\Controllers\World\AiWorldPrivacyController;
use Modules\AI\Http\Controllers\World\AiWorldProjectController;
use Modules\AI\Http\Controllers\AiChatController;
use Modules\AI\Http\Controllers\AiHistoryController;
use Modules\AI\Http\Controllers\AiMemoryController;
use Modules\AI\Http\Controllers\AiUserProjectController;
use Modules\AI\Http\Controllers\AiConversationFileController;
use Modules\AI\Http\Controllers\AiRealtimeController;
use Modules\AI\Http\Controllers\AiSiteHostingController;
use Modules\AI\Http\Controllers\AiSiteProjectController;
use Modules\AI\Http\Controllers\AiSiteServiceRequestController;
use Modules\AI\Http\Controllers\AiUserSubscriptionController;
use Modules\Wallet\Http\Middleware\RequiresWalletPin;

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

    // DORR AI's own tools (2026-10-14): memory (spec 140–144), the outputs gallery (344), a
    // conversation's Context Capsule (121–132) and the person's projects (151). Search and
    // "continue in a new chat" call the AI, so they sit with the send limiter below.
    Route::get('memories', [AiMemoryController::class, 'index']);
    Route::post('memories', [AiMemoryController::class, 'store']);
    Route::put('memories/settings', [AiMemoryController::class, 'settings']);
    Route::delete('memories', [AiMemoryController::class, 'destroyAll']);
    Route::put('memories/{memory}', [AiMemoryController::class, 'update'])->whereNumber('memory');
    Route::delete('memories/{memory}', [AiMemoryController::class, 'destroy'])->whereNumber('memory');

    Route::get('outputs', [AiHistoryController::class, 'outputs']);
    Route::get('conversations/{conversation}/capsule', [AiHistoryController::class, 'capsule'])->whereNumber('conversation');
    Route::delete('conversations/{conversation}/capsule', [AiHistoryController::class, 'destroyCapsule'])->whereNumber('conversation');

    Route::get('projects', [AiUserProjectController::class, 'index']);
    Route::post('projects', [AiUserProjectController::class, 'store']);
    Route::get('projects/{project}', [AiUserProjectController::class, 'show'])->whereNumber('project');
    Route::put('projects/{project}', [AiUserProjectController::class, 'update'])->whereNumber('project');
    Route::delete('projects/{project}', [AiUserProjectController::class, 'destroy'])->whereNumber('project');
    Route::post('projects/{project}/conversations/{conversation}', [AiUserProjectController::class, 'addConversation'])->whereNumber(['project', 'conversation']);
    Route::delete('projects/{project}/conversations/{conversation}', [AiUserProjectController::class, 'removeConversation'])->whereNumber(['project', 'conversation']);
    Route::post('projects/{project}/files/{file}', [AiUserProjectController::class, 'addFile'])->whereNumber(['project', 'file']);
    Route::delete('projects/{project}/files/{file}', [AiUserProjectController::class, 'removeFile'])->whereNumber(['project', 'file']);
});

// Sending a message triggers a real, paid AI provider call, so it carries
// only the dedicated, stricter ai-chat-send limiter - not stacked on top
// of the generous ai-chat-general one the read/list endpoints above use.
Route::middleware(['locale', 'auth:provider_api', 'throttle:ai-chat-send'])->prefix('provider/v1/ai-chat')->group(function () {
    Route::post('conversations/{conversation}/messages', [AiChatController::class, 'sendMessage']);

    // v2.0 requirements doc 18.2 (streaming): progressive delivery of
    // the already-verified answer - see AiChatService::streamMessage().
    Route::post('conversations/{conversation}/messages/stream', [AiChatController::class, 'streamMessage']);

    // Search by meaning (spec 134–139) and "continue in a new chat" (spec 123) each make one AI call.
    Route::get('search', [AiHistoryController::class, 'search']);
    Route::post('conversations/continue', [AiHistoryController::class, 'continue']);
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

// Website builder. Reading/listing/editing use the general limiter; anything
// that spends AI quota or money is stricter, and a standalone purchase also
// needs the wallet PIN + a resolved country (price is per country).
Route::middleware(['locale', 'country', 'auth:provider_api', 'throttle:ai-chat-general'])->prefix('provider/v1/ai-sites')->group(function () {
    Route::get('offers', [AiSiteProjectController::class, 'offers']);
    Route::get('/', [AiSiteProjectController::class, 'index']);
    Route::get('{project}', [AiSiteProjectController::class, 'show'])->whereNumber('project');
    Route::get('{project}/download', [AiSiteProjectController::class, 'download'])->whereNumber('project');
    Route::get('{project}/edit-packs', [AiSiteProjectController::class, 'editPacks'])->whereNumber('project');
    Route::get('{project}/source-download', [AiSiteProjectController::class, 'sourceDownload'])->whereNumber('project');
    Route::delete('{project}', [AiSiteProjectController::class, 'destroy'])->whereNumber('project');
});

Route::middleware(['locale', 'country', 'auth:provider_api', 'throttle:ai-chat-send'])->prefix('provider/v1/ai-sites')->group(function () {
    Route::post('/', [AiSiteProjectController::class, 'store']);
    Route::post('{project}/edit', [AiSiteProjectController::class, 'edit'])->whereNumber('project');
    Route::post('{project}/retry', [AiSiteProjectController::class, 'retry'])->whereNumber('project');
    Route::post('{project}/versions/{number}/restore', [AiSiteProjectController::class, 'restore'])->whereNumber(['project', 'number']);
});

Route::middleware(['locale', 'country', 'auth:provider_api', 'throttle:ai-chat-send', RequiresWalletPin::class])->prefix('provider/v1/ai-sites')->group(function () {
    Route::post('purchase', [AiSiteProjectController::class, 'purchase']);
    Route::post('{project}/edit-packs', [AiSiteProjectController::class, 'buyEditPack'])->whereNumber('project');
    Route::post('{project}/source-download', [AiSiteProjectController::class, 'buySourceDownload'])->whereNumber('project');
});

// Site hosting (sub-domain, monthly/yearly). Money-moving calls need the wallet PIN.
Route::middleware(['locale', 'country', 'auth:provider_api', 'throttle:ai-chat-general'])->prefix('provider/v1/ai-sites')->group(function () {
    Route::get('hosting/plans', [AiSiteHostingController::class, 'plans']);
    Route::get('hosting/check', [AiSiteHostingController::class, 'check']);
    Route::get('{project}/hosting', [AiSiteHostingController::class, 'show'])->whereNumber('project');
    Route::put('{project}/hosting/auto-renew', [AiSiteHostingController::class, 'autoRenew'])->whereNumber('project');
    Route::post('{project}/hosting/publish', [AiSiteHostingController::class, 'publish'])->whereNumber('project');
});

Route::middleware(['locale', 'country', 'auth:provider_api', 'throttle:ai-chat-send', RequiresWalletPin::class])->prefix('provider/v1/ai-sites')->group(function () {
    Route::post('{project}/hosting', [AiSiteHostingController::class, 'subscribe'])->whereNumber('project');
    Route::post('{project}/hosting/renew', [AiSiteHostingController::class, 'renew'])->whereNumber('project');
});

// "Build it with a person": brief -> quote -> accept (PIN) -> instalments (PIN) -> handover, plus the conversation.
Route::middleware(['locale', 'country', 'auth:provider_api', 'throttle:ai-chat-general'])->prefix('provider/v1/ai-site-requests')->group(function () {
    Route::get('info', [AiSiteServiceRequestController::class, 'info']);
    Route::get('/', [AiSiteServiceRequestController::class, 'index']);
    Route::get('{id}', [AiSiteServiceRequestController::class, 'show'])->whereNumber('id');
    Route::get('{id}/messages', [AiSiteServiceRequestController::class, 'messages'])->whereNumber('id');
});

Route::middleware(['locale', 'country', 'auth:provider_api', 'throttle:ai-chat-send'])->prefix('provider/v1/ai-site-requests')->group(function () {
    Route::post('/', [AiSiteServiceRequestController::class, 'store']);
    Route::post('{id}/cancel', [AiSiteServiceRequestController::class, 'cancel'])->whereNumber('id');
    Route::post('{id}/decline', [AiSiteServiceRequestController::class, 'decline'])->whereNumber('id');
    Route::post('{id}/messages', [AiSiteServiceRequestController::class, 'sendMessage'])->whereNumber('id');
});

Route::middleware(['locale', 'country', 'auth:provider_api', 'throttle:ai-chat-send', RequiresWalletPin::class])->prefix('provider/v1/ai-site-requests')->group(function () {
    Route::post('{id}/accept', [AiSiteServiceRequestController::class, 'accept'])->whereNumber('id');
    Route::post('{id}/installments/{installment}/pay', [AiSiteServiceRequestController::class, 'pay'])->whereNumber(['id', 'installment']);
});

// DORR AI WORLD: availability and place search.
Route::middleware(['locale', 'country', 'auth:provider_api', 'throttle:ai-chat-general'])->prefix('provider/v1/ai-world')->group(function () {
    Route::get('info', [AiWorldController::class, 'info']);
    Route::get('locations/search', [AiWorldController::class, 'searchLocations']);
    Route::get('locations/{id}', [AiWorldController::class, 'showLocation'])->whereNumber('id');
});

// DORR AI WORLD projects. Confirm and regenerate spend wallet money, so they need the wallet PIN.
Route::middleware(['locale', 'country', 'auth:provider_api', 'throttle:ai-chat-general'])->prefix('provider/v1/ai-world/projects')->group(function () {
    Route::get('/', [AiWorldProjectController::class, 'index']);
    Route::post('plan', [AiWorldProjectController::class, 'plan']);
    Route::post('/', [AiWorldProjectController::class, 'store']);
    Route::get('{id}', [AiWorldProjectController::class, 'show'])->whereNumber('id');
    Route::post('{id}/cancel', [AiWorldProjectController::class, 'cancel'])->whereNumber('id');
    Route::post('{id}/scenes/{sceneId}/approve', [AiWorldProjectController::class, 'approveScene'])->whereNumber(['id', 'sceneId']);
});

Route::middleware(['locale', 'country', 'auth:provider_api', 'throttle:ai-chat-send', RequiresWalletPin::class])->prefix('provider/v1/ai-world/projects')->group(function () {
    Route::post('{id}/confirm', [AiWorldProjectController::class, 'confirm'])->whereNumber('id');
    Route::post('{id}/scenes/{sceneId}/regenerate', [AiWorldProjectController::class, 'regenerateScene'])->whereNumber(['id', 'sceneId']);
});

// DORR AI WORLD privacy: reference photos, explicit consent, immediate deletion and reports.
Route::middleware(['locale', 'country', 'auth:provider_api', 'throttle:ai-chat-general'])->prefix('provider/v1/ai-world')->group(function () {
    Route::get('consent-text', [AiWorldPrivacyController::class, 'consentText']);
    Route::get('identity-profiles', [AiWorldPrivacyController::class, 'profiles']);
    Route::post('identity-profiles', [AiWorldPrivacyController::class, 'storeProfile']);
    Route::post('identity-profiles/{id}/consent', [AiWorldPrivacyController::class, 'consent'])->whereNumber('id');
    Route::delete('identity-profiles/{id}', [AiWorldPrivacyController::class, 'destroyProfile'])->whereNumber('id');
    Route::delete('projects/{id}', [AiWorldPrivacyController::class, 'destroyProject'])->whereNumber('id');
    Route::post('projects/{id}/report', [AiWorldPrivacyController::class, 'report'])->whereNumber('id');
});

// DORR AR engine: model library, experiences (versioned scenes), device capability, launch check.
Route::middleware(['locale', 'country', 'auth:provider_api', 'throttle:ai-chat-general'])->prefix('provider/v1/ai-ar')->group(function () {
    Route::get('info', [AiArController::class, 'info']);
    Route::post('capability', [AiArController::class, 'capability']);
    Route::get('launch-check', [AiArController::class, 'launchCheck']);
    Route::post('command', [AiArController::class, 'command']);
    Route::post('vision', [AiArController::class, 'vision']);
    Route::get('providers/search', [AiArController::class, 'searchProviders']);
    Route::get('experiences/{id}/reviews', [AiArController::class, 'reviews'])->whereNumber('id');
    Route::post('experiences/{id}/submit', [AiArController::class, 'submitForReview'])->whereNumber('id');
    Route::post('experiences/{id}/reviews/{approvalId}/withdraw', [AiArController::class, 'withdrawReview'])->whereNumber(['id', 'approvalId']);
    Route::post('experiences/{id}/accept', [AiArController::class, 'acceptDesign'])->whereNumber('id');
    Route::get('experiences/{id}/order', [AiArOrderController::class, 'forExperience'])->whereNumber('id');
    Route::post('orders/{id}/cancel', [AiArOrderController::class, 'cancel'])->whereNumber('id');
    Route::post('orders/{id}/confirm', [AiArOrderController::class, 'confirm'])->whereNumber('id');
    Route::post('orders/{id}/dispute', [AiArOrderController::class, 'dispute'])->whereNumber('id');
    Route::get('inbox', [AiArController::class, 'inbox']);
    Route::get('inbox/{id}', [AiArController::class, 'inboxShow'])->whereNumber('id');
    Route::post('inbox/{id}/decide', [AiArController::class, 'decide'])->whereNumber('id');
    Route::get('jobs', [AiArOrderController::class, 'jobs']);
    Route::post('jobs/{id}/deliver', [AiArOrderController::class, 'deliver'])->whereNumber('id');
    Route::post('jobs/{id}/decline', [AiArOrderController::class, 'decline'])->whereNumber('id');
    Route::post('experiences/{id}/captures', [AiArController::class, 'storeCapture'])->whereNumber('id');
    Route::delete('experiences/{id}/captures/{captureId}', [AiArController::class, 'destroyCapture'])->whereNumber(['id', 'captureId']);
    Route::get('assets', [AiArController::class, 'assetsIndex']);
    Route::post('assets', [AiArController::class, 'storeAsset']);
    Route::get('assets/{id}', [AiArController::class, 'assetShow'])->whereNumber('id');
    Route::get('experiences', [AiArController::class, 'experiencesIndex']);
    Route::post('experiences', [AiArController::class, 'storeExperience']);
    Route::get('experiences/{id}', [AiArController::class, 'showExperience'])->whereNumber('id');
    Route::put('experiences/{id}/scene', [AiArController::class, 'saveScene'])->whereNumber('id');
    Route::delete('experiences/{id}', [AiArController::class, 'destroyExperience'])->whereNumber('id');
});

// Paying moves wallet money, so it carries the wallet PIN like every other charge (X-Wallet-Pin).
Route::middleware(['locale', 'country', 'auth:provider_api', 'throttle:ai-chat-send', RequiresWalletPin::class])->prefix('provider/v1/ai-ar')->group(function () {
    Route::post('experiences/{id}/accept-and-pay', [AiArOrderController::class, 'acceptAndPay'])->whereNumber('id');
});
