<?php

use Illuminate\Support\Facades\Route;
use Modules\Wallet\Http\Controllers\Provider\WithdrawalController;
use Modules\Wallet\Http\Controllers\Provider\WithdrawalMethodController;
use Modules\Wallet\Http\Middleware\RequiresWalletPin;
use Modules\Wallet\Http\Middleware\ResolveWalletOwner;

Route::middleware('locale')->prefix('provider/v1')->group(function () {
    // ResolveWalletOwner: in the providers app the balance, statement and payouts are the establishment's (docs/moving_tasks.md A-10).
    Route::middleware(['auth:provider_api', 'country', 'remember-locale', ResolveWalletOwner::class])->group(function () {
        require __DIR__.'/customer.php';

        // Withdrawal is provider-only for now (wallet-structure.md §6).
        Route::get('wallet/withdrawal-methods', [WithdrawalMethodController::class, 'index']);
        Route::patch('wallet/withdrawal-methods/{method}/favorite', [WithdrawalMethodController::class, 'favorite']);
        Route::delete('wallet/withdrawal-methods/{method}', [WithdrawalMethodController::class, 'destroy']);

        Route::get('wallet/withdrawals', [WithdrawalController::class, 'index']);
        Route::get('wallet/withdrawals/{withdrawal}', [WithdrawalController::class, 'show']);
        Route::get('wallet/withdrawals/{withdrawal}/receipt', [WithdrawalController::class, 'receipt']);

        // Redirecting or triggering a payout needs the wallet PIN.
        Route::middleware(RequiresWalletPin::class)->group(function () {
            Route::post('wallet/withdrawal-methods', [WithdrawalMethodController::class, 'store']);
            Route::put('wallet/withdrawal-methods/{method}', [WithdrawalMethodController::class, 'update']);
            Route::post('wallet/withdrawals', [WithdrawalController::class, 'store']);
        });
    });
});
