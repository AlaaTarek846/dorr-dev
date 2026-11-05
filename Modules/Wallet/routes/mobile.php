<?php

use Illuminate\Support\Facades\Route;
use Modules\Freelance\Http\Middleware\UseFreelanceWallet;
use Modules\Wallet\Http\Controllers\Provider\WithdrawalController;
use Modules\Wallet\Http\Controllers\Provider\WithdrawalMethodController;
use Modules\Wallet\Http\Controllers\User\TransferController;
use Modules\Wallet\Http\Middleware\RequiresWalletPin;

Route::middleware('locale')->prefix('mobile/v1')->group(function () {
    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api', 'throttle:60,1', 'country', 'remember-locale'])->group(function () {
        require __DIR__.'/customer.php';

        // "People I've already sent money to, in this country" — a quick-pick list.
        Route::get('wallet/beneficiaries', [TransferController::class, 'beneficiaries']);

        // User → user transfer (providers don't send transfers), in two steps:
        // look the recipient up and confirm who it is (moves nothing, but rate-limited so it
        // can't be used to scan for accounts), then pay — which moves money, so PIN.
        Route::post('wallet/transfers/lookup', [TransferController::class, 'lookup'])->middleware('throttle:20,1,transfer-lookup');
        Route::post('wallet/transfers', [TransferController::class, 'store'])->middleware(RequiresWalletPin::class);
    });
});

/*
 * The client's international wallet (freelance, owner 2026-11-04): the same wallet screens on the freelance currency, next to the
 * local wallet above. The client tops it up, pays freelance work from it and withdraws from it, but never transfers from it: no
 * transfer routes here, and a local transfer only finds wallets of the sender's own country.
 */
Route::middleware('locale')->prefix('mobile/v1/freelance')->group(function () {
    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api', 'throttle:60,1', 'country', UseFreelanceWallet::class, 'remember-locale'])->group(function () {
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
