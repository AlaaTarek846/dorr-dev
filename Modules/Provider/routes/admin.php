<?php

use Illuminate\Support\Facades\Route;
use Modules\Provider\Http\Controllers\ProviderController;

Route::middleware(['locale', 'auth:admin_api'])->prefix('admin/v1')->group(function () {
    Route::post('providers/delete-multiple', [ProviderController::class, 'deleteMultiple']);
    Route::post('providers/{provider}/restore', [ProviderController::class, 'restore']);
    Route::delete('providers/{provider}/force', [ProviderController::class, 'forceDestroy']);
    Route::patch('providers/{provider}/status', [ProviderController::class, 'changeStatus']);
    Route::apiResource('providers', ProviderController::class);
});
