<?php

use Illuminate\Support\Facades\Route;
use Modules\Provider\Http\Controllers\ProviderProfileController;

Route::middleware(['locale', 'auth:admin_api'])->prefix('admin/v1/providers')->group(function () {
    Route::get('/', [ProviderProfileController::class, 'index']);
    Route::get('{provider}', [ProviderProfileController::class, 'show']);

    Route::post('{provider}/approve', [ProviderProfileController::class, 'approve']);
    Route::post('{provider}/reject', [ProviderProfileController::class, 'reject']);
    Route::post('{provider}/suspend', [ProviderProfileController::class, 'suspend']);
    Route::post('{provider}/reactivate', [ProviderProfileController::class, 'reactivate']);

    Route::post('{provider}/services', [ProviderProfileController::class, 'addService']);
    Route::post('{provider}/services/{service}/approve', [ProviderProfileController::class, 'approveService']);
    Route::post('{provider}/services/{service}/reject', [ProviderProfileController::class, 'rejectService']);
});
