<?php

use Illuminate\Support\Facades\Route;
use Modules\Sms\Http\Controllers\SmsController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('sms', SmsController::class)->names('sms');
});
