<?php

use Illuminate\Support\Facades\Route;
use Modules\Wallet\Http\Controllers\User\TransferController;
use Modules\Wallet\Http\Middleware\RequiresWalletPin;

Route::middleware('locale')->prefix('mobile/v1')->group(function () {
    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api', 'throttle:60,1', 'country', 'remember-locale'])->group(function () {
        require __DIR__.'/customer.php';

        // User → user transfer (providers don't send transfers), in two steps:
        // look the recipient up and confirm who it is (moves nothing, but rate-limited so it
        // can't be used to scan for accounts), then pay — which moves money, so PIN.
        Route::post('wallet/transfers/lookup', [TransferController::class, 'lookup'])->middleware('throttle:20,1');
        Route::post('wallet/transfers', [TransferController::class, 'store'])->middleware(RequiresWalletPin::class);
    });
});
