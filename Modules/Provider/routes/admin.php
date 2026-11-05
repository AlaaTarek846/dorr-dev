<?php

use Illuminate\Support\Facades\Route;
use Modules\Provider\Http\Controllers\Admin\EstablishmentAdminController;
use Modules\Provider\Http\Controllers\Admin\ProviderDocumentTypeAdminController;
use Modules\Provider\Http\Controllers\Admin\ProviderVehicleTypeAdminController;
use Modules\Provider\Http\Controllers\ProviderController;

Route::middleware(['locale', 'auth:admin_api'])->prefix('admin/v1')->group(function () {
    Route::post('providers/delete-multiple', [ProviderController::class, 'deleteMultiple']);
    Route::post('providers/{provider}/restore', [ProviderController::class, 'restore']);
    Route::delete('providers/{provider}/force', [ProviderController::class, 'forceDestroy']);
    Route::patch('providers/{provider}/status', [ProviderController::class, 'changeStatus']);
    Route::apiResource('providers', ProviderController::class);

    // Provider establishments, vehicle types and documents asked (docs/moving_tasks.md A-11).
    Route::get('provider-establishments', [EstablishmentAdminController::class, 'index']);
    Route::get('provider-establishments/{establishment}', [EstablishmentAdminController::class, 'show']);
    Route::patch('provider-establishments/{establishment}/status', [EstablishmentAdminController::class, 'status']);
    Route::patch('provider-establishments/{establishment}/vehicles/{vehicle}/review', [EstablishmentAdminController::class, 'reviewVehicle']);
    Route::patch('provider-establishments/{establishment}/documents/{document}/review', [EstablishmentAdminController::class, 'reviewDocument']);

    Route::get('provider-vehicle-types/options', [ProviderVehicleTypeAdminController::class, 'options']);
    Route::get('provider-vehicle-types', [ProviderVehicleTypeAdminController::class, 'index']);
    Route::post('provider-vehicle-types', [ProviderVehicleTypeAdminController::class, 'store']);
    // POST, not PUT: the image is a multipart upload.
    Route::post('provider-vehicle-types/{vehicleType}', [ProviderVehicleTypeAdminController::class, 'update'])->whereNumber('vehicleType');
    Route::delete('provider-vehicle-types/{vehicleType}', [ProviderVehicleTypeAdminController::class, 'destroy'])->whereNumber('vehicleType');

    Route::get('provider-document-types/options', [ProviderDocumentTypeAdminController::class, 'options']);
    Route::get('provider-document-types', [ProviderDocumentTypeAdminController::class, 'index']);
    Route::post('provider-document-types', [ProviderDocumentTypeAdminController::class, 'store']);
    Route::put('provider-document-types/{documentType}', [ProviderDocumentTypeAdminController::class, 'update'])->whereNumber('documentType');
    Route::delete('provider-document-types/{documentType}', [ProviderDocumentTypeAdminController::class, 'destroy'])->whereNumber('documentType');
});
