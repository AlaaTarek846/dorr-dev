<?php

use Illuminate\Support\Facades\Route;
use Modules\Wallet\Http\Controllers\General\AvailablePaymentMethodController;
use Modules\Wallet\Http\Controllers\General\TopupController;
use Modules\Wallet\Http\Controllers\General\WalletBalanceController;
use Modules\Wallet\Http\Controllers\General\WalletEligibilityController;
use Modules\Wallet\Http\Controllers\General\WalletPinController;
use Modules\Wallet\Http\Controllers\General\WalletTransactionController;
use Modules\Wallet\Http\Middleware\RequiresWalletPin;

/*
 * Wallet endpoints shared by both wallet owners (user + provider). Required
 * from inside each audience's own route group (mobile.php / provider.php),
 * which supplies the prefix, auth guard and `country` middleware.
 */
Route::get('wallet', WalletBalanceController::class);
Route::get('wallet/transactions', WalletTransactionController::class);
Route::get('wallet/eligibility', WalletEligibilityController::class);
Route::get('wallet/payment-methods', AvailablePaymentMethodController::class);

Route::get('wallet/pin', [WalletPinController::class, 'show']);
Route::post('wallet/pin', [WalletPinController::class, 'store']);
Route::put('wallet/pin', [WalletPinController::class, 'update']);

Route::post('wallet/topups/quote', [TopupController::class, 'quote']);
Route::get('wallet/topups/{uuid}', [TopupController::class, 'show']);

// Money-moving: the wallet PIN is required (X-Wallet-Pin header).
Route::middleware(RequiresWalletPin::class)->group(function () {
    Route::post('wallet/pin/verify', [WalletPinController::class, 'verify']);
    Route::post('wallet/topups', [TopupController::class, 'store']);
    Route::post('wallet/topups/{uuid}/confirm', [TopupController::class, 'confirm']);
});
