<?php

use Illuminate\Support\Facades\Route;
use Modules\Wallet\Http\Controllers\WalletController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('wallets', WalletController::class)->names('wallet');
});

// Real wallet routes, added alongside the default nwidart stub above (which
// is left untouched on purpose) — one file per audience, same convention as
// Modules/Admin, Modules/User, Modules/Provider.
require __DIR__.'/admin.php';
require __DIR__.'/mobile.php';
require __DIR__.'/provider.php';
require __DIR__.'/public.php';
