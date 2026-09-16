<?php

use Illuminate\Support\Facades\Route;
use Modules\Provider\Http\Controllers\ProviderController;

Route::middleware(['locale', 'auth:admin_api'])->prefix('admin/v1')->group(function () {
    Route::post('providers/delete-multiple', [ProviderController::class, 'deleteMultiple']);
    Route::patch('providers/{provider}/status', [ProviderController::class, 'changeStatus']);
    Route::apiResource('providers', ProviderController::class);
});
