<?php

use Illuminate\Support\Facades\Route;
use Modules\Wallet\Http\Controllers\Admin\FinancialEntryController;
use Modules\Wallet\Http\Controllers\Admin\OnlineTransactionController;
use Modules\Wallet\Http\Controllers\Admin\PaymentMethodController;
use Modules\Wallet\Http\Controllers\Admin\WalletController;
use Modules\Wallet\Http\Controllers\Admin\WithdrawalRequestController;
use Modules\Wallet\Http\Controllers\Admin\WalletFeeRuleController;
use Modules\Wallet\Http\Controllers\Admin\WalletSettingController;

Route::middleware('locale')->prefix('admin/v1')->group(function () {
    Route::middleware('auth:admin_api')->group(function () {
        Route::get('payment-methods/dropdown', [PaymentMethodController::class, 'dropdown']);
        Route::post('payment-methods/delete-multiple', [PaymentMethodController::class, 'deleteMultiple']);
        Route::post('payment-methods/{payment_method}/restore', [PaymentMethodController::class, 'restore']);
        Route::delete('payment-methods/{payment_method}/force', [PaymentMethodController::class, 'forceDestroy']);
        Route::patch('payment-methods/{payment_method}/status', [PaymentMethodController::class, 'changeStatus']);
        Route::put('payment-methods/{payment_method}/countries', [PaymentMethodController::class, 'syncCountries']);
        Route::apiResource('payment-methods', PaymentMethodController::class);

        Route::post('wallet-fee-rules/delete-multiple', [WalletFeeRuleController::class, 'deleteMultiple']);
        Route::post('wallet-fee-rules/{wallet_fee_rule}/restore', [WalletFeeRuleController::class, 'restore']);
        Route::delete('wallet-fee-rules/{wallet_fee_rule}/force', [WalletFeeRuleController::class, 'forceDestroy']);
        Route::patch('wallet-fee-rules/{wallet_fee_rule}/status', [WalletFeeRuleController::class, 'changeStatus']);
        Route::apiResource('wallet-fee-rules', WalletFeeRuleController::class);

        Route::get('wallets', [WalletController::class, 'index']);
        Route::get('wallets/{wallet}', [WalletController::class, 'show']);
        Route::get('wallets/{wallet}/transactions', [WalletController::class, 'transactions']);
        Route::post('wallets/{wallet}/adjustments', [WalletController::class, 'adjust']);

        Route::get('financial-entries/summary', [FinancialEntryController::class, 'summary']);
        Route::get('financial-entries', [FinancialEntryController::class, 'index']);

        Route::get('wallet-settings', [WalletSettingController::class, 'index']);
        Route::get('wallet-settings/{country}', [WalletSettingController::class, 'show']);
        Route::put('wallet-settings/{country}', [WalletSettingController::class, 'update']);

        Route::get('withdrawal-requests',[WithdrawalRequestController::class, 'index']);
        Route::get('withdrawal-requests/{withdrawal_request}', [WithdrawalRequestController::class, 'show']);
        Route::get('withdrawal-requests/{withdrawal_request}/receipt', [WithdrawalRequestController::class, 'receipt']);
        Route::post('withdrawal-requests/{withdrawal_request}/approve', [WithdrawalRequestController::class, 'approve']);
        Route::post('withdrawal-requests/{withdrawal_request}/reject', [WithdrawalRequestController::class, 'reject']);

        Route::get('online-transactions/summary', [OnlineTransactionController::class, 'summary']);
        Route::get('online-transactions', [OnlineTransactionController::class, 'index']);
        Route::get('online-transactions/{online_transaction}', [OnlineTransactionController::class, 'show']);
        Route::post('online-transactions/{online_transaction}/reconcile', [OnlineTransactionController::class, 'reconcile']);
        Route::post('online-transactions/{online_transaction}/refund', [OnlineTransactionController::class, 'refund']);
    });
});
