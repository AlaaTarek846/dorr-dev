<?php

use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\AiUserSubscriptionController;
use Modules\Wallet\Http\Middleware\RequiresWalletPin;

// DORR AI plans for captains (rides Block 06): DORR Driver AI and the demand forecast are AI tools, so they
// need a plan like every AI tool (ADR-013). Same plans, same billing from the captain's own wallet, same
// wallet PIN to pay — the owner is the captain (owner_type `driver`).
Route::middleware(['locale', 'country', 'auth:driver_api', 'throttle:ai-chat-general'])->prefix('driver/v1/ai-subscription')->group(function () {
    Route::get('plans', [AiUserSubscriptionController::class, 'plans']);
    Route::get('/', [AiUserSubscriptionController::class, 'current']);
    Route::get('payments', [AiUserSubscriptionController::class, 'payments']);
    Route::put('auto-renew', [AiUserSubscriptionController::class, 'updateAutoRenew']);
});

Route::middleware(['locale', 'country', 'auth:driver_api', 'throttle:ai-chat-send', RequiresWalletPin::class])->prefix('driver/v1/ai-subscription')->group(function () {
    Route::post('subscribe', [AiUserSubscriptionController::class, 'subscribe']);
    Route::post('change-plan', [AiUserSubscriptionController::class, 'changePlan']);
});
