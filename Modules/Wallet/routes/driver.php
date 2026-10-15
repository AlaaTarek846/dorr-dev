<?php

use Illuminate\Support\Facades\Route;
use Modules\Wallet\Http\Controllers\Provider\WithdrawalController;
use Modules\Wallet\Http\Controllers\Provider\WithdrawalMethodController;
use Modules\Wallet\Http\Middleware\RequiresWalletPin;

/*
 * The captain's wallet in the driver app (docs/modules/rides/README.md): the same wallet screens and
 * money rules as the rider's and the provider's — balance, statement, PIN, and withdrawing the
 * earnings (settled by Modules/Rides RideSettlementService) — on the `driver_api` guard.
 */
Route::middleware('locale')->prefix('driver/v1')->group(function () {
    Route::middleware(['auth:driver_api', 'ensure-phone-verified:driver_api', 'country', 'remember-locale'])->group(function () {
        require __DIR__.'/customer.php';

        Route::get('wallet/withdrawal-methods', [WithdrawalMethodController::class, 'index']);
        Route::patch('wallet/withdrawal-methods/{method}/favorite', [WithdrawalMethodController::class, 'favorite']);
        Route::delete('wallet/withdrawal-methods/{method}', [WithdrawalMethodController::class, 'destroy']);

        Route::get('wallet/withdrawals', [WithdrawalController::class, 'index']);
        Route::get('wallet/withdrawals/{withdrawal}', [WithdrawalController::class, 'show']);
        Route::get('wallet/withdrawals/{withdrawal}/receipt', [WithdrawalController::class, 'receipt']);

        Route::middleware(RequiresWalletPin::class)->group(function () {
            Route::post('wallet/withdrawal-methods', [WithdrawalMethodController::class, 'store']);
            Route::put('wallet/withdrawal-methods/{method}', [WithdrawalMethodController::class, 'update']);
            Route::post('wallet/withdrawals', [WithdrawalController::class, 'store']);
        });
    });
});
