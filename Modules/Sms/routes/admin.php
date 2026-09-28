<?php

use Illuminate\Support\Facades\Route;
use Modules\Sms\Http\Controllers\OtpController;
use Modules\Sms\Http\Controllers\SmsProviderController;
use Modules\Sms\Http\Controllers\WhatsAppController;

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
     | WhatsApp
     * ------------------------------------------------------------------ */
    Route::get('whatsapp', [WhatsAppController::class, 'index']);
    Route::get('whatsapp/show', [WhatsAppController::class, 'show']);
    Route::post('whatsapp', [WhatsAppController::class, 'store']);
    Route::patch('whatsapp', [WhatsAppController::class, 'update']);
    Route::post('whatsapp/test-connection', [WhatsAppController::class, 'testConnection']);
    Route::post('whatsapp/sync-template', [WhatsAppController::class, 'syncTemplate']);

    /* ------------------------------------------------------------------ *
     | OTP
     * ------------------------------------------------------------------ */
    Route::get('otp', [OtpController::class, 'index']);
    Route::patch('otp', [OtpController::class, 'update']);
    Route::post('otp/send', [OtpController::class, 'send']);
});
