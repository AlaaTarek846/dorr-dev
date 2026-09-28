<?php

use Illuminate\Support\Facades\Route;
use Modules\Sms\Http\Controllers\SmsAccountController;
use Modules\Sms\Http\Controllers\SmsProviderController;

Route::middleware('auth:admin_api')->group(function () {
    /* ------------------------------------------------------------------ *
     | SMS providers
     * ------------------------------------------------------------------ */
    Route::get('sms-providers/dropdown', [SmsProviderController::class, 'dropdown']);
    Route::get('sms-providers/types', [SmsProviderController::class, 'types']);
    Route::post('sms-providers/delete-multiple', [SmsProviderController::class, 'deleteMultiple']);
    Route::post('sms-providers/test-draft', [SmsProviderController::class, 'testDraft']);
    Route::post('sms-providers/{sms_provider}/test', [SmsProviderController::class, 'test']);
    Route::patch('sms-providers/{sms_provider}/status', [SmsProviderController::class, 'toggleActive']);
    Route::apiResource('sms-providers', SmsProviderController::class)
        ->parameters(['sms-providers' => 'sms_provider'])
        ->names('sms-providers');

    /* ------------------------------------------------------------------ *
     | SMS accounts
     * ------------------------------------------------------------------ */
    Route::get('sms-accounts/dropdown', [SmsAccountController::class, 'dropdown']);
    Route::get('sms-accounts/providers-dropdown', [SmsAccountController::class, 'providersDropdown']);
    Route::post('sms-accounts/test-draft', [SmsAccountController::class, 'testDraft']);
    Route::post('sms-accounts/send-test', [SmsAccountController::class, 'sendTest']);
    Route::post('sms-accounts/delete-multiple', [SmsAccountController::class, 'deleteMultiple']);
    Route::post('sms-accounts/{sms_account}/test', [SmsAccountController::class, 'test']);
    Route::get('sms-accounts/{sms_account}/balance', [SmsAccountController::class, 'balance']);
    Route::post('sms-accounts/{sms_account}/set-default', [SmsAccountController::class, 'setDefault']);
    Route::patch('sms-accounts/{sms_account}/status', [SmsAccountController::class, 'toggleActive']);
    Route::apiResource('sms-accounts', SmsAccountController::class)
        ->parameters(['sms-accounts' => 'sms_account'])
        ->names('sms-accounts');
});
