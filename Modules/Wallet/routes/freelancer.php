<?php

use Illuminate\Support\Facades\Route;
use Modules\Wallet\Http\Controllers\Provider\WithdrawalController;
use Modules\Wallet\Http\Controllers\Provider\WithdrawalMethodController;
use Modules\Wallet\Http\Middleware\RequiresWalletPin;

/*
 * The freelancer's wallet (docs/freelance_tasks.md FL-005): the same wallet screens and money rules as
 * the rider's and the captain's — balance, statement, PIN, topping up (to pay for a plan later) and, by the
 * owner's decision (2026-10-20), withdrawing the earnings — on the `freelance_api` guard. The withdrawal
 * routes are the captain's (driver.php): a request holds the money, an admin approves it. No
 * user-to-user transfers (FL-508 → FL-L15).
 */
Route::middleware('locale')->prefix('freelance/v1')->group(function () {
    Route::middleware(['auth:freelance_api', 'ensure-phone-verified:freelance_api', 'throttle:60,1', 'country', 'remember-locale'])->group(function () {
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
