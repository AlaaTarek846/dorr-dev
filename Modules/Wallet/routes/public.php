<?php

use Illuminate\Support\Facades\Route;
use Modules\Wallet\Http\Controllers\General\PaymentCallbackController;
use Modules\Wallet\Http\Controllers\General\SandboxCheckoutController;

// The gateway sends the customer's browser back here (GET redirect or POST
// form, depending on the gateway) — see PaymentCallbackController.
Route::middleware(['locale', 'throttle:60,1'])->prefix('wallet')->group(function () {
    Route::match(['get', 'post'], 'payments/{uuid}/callback', PaymentCallbackController::class)
        ->name('wallet.payments.callback');

    // Development/demo fake bank — both endpoints 404 unless wallet.sandbox_enabled.
    Route::get('sandbox/{reference}', [SandboxCheckoutController::class, 'show'])->name('wallet.sandbox.checkout');
    Route::post('sandbox/{reference}', [SandboxCheckoutController::class, 'decide'])->name('wallet.sandbox.decide');
});
