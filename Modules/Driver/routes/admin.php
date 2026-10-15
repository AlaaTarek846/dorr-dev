<?php

use Illuminate\Support\Facades\Route;
use Modules\Driver\Http\Controllers\Admin\DriverController;
use Modules\Driver\Http\Controllers\Admin\DriverDocumentTypeController;

// Admin → Drivers (docs/rides-plan.md §11.3): drivers, their vehicles and documents, document types.
Route::middleware('locale')->prefix('admin/v1')->group(function () {
    Route::middleware('auth:admin_api')->group(function () {
        Route::get('drivers/options', [DriverController::class, 'options']);
        Route::get('drivers', [DriverController::class, 'index']);
        Route::post('drivers', [DriverController::class, 'store']);
        Route::get('drivers/{driver}', [DriverController::class, 'show'])->whereNumber('driver');
        Route::put('drivers/{driver}', [DriverController::class, 'update'])->whereNumber('driver');
        Route::patch('drivers/{driver}/status', [DriverController::class, 'status'])->whereNumber('driver');

        Route::post('drivers/{driver}/vehicles', [DriverController::class, 'storeVehicle'])->whereNumber('driver');
        Route::put('drivers/{driver}/vehicles/{vehicle}', [DriverController::class, 'updateVehicle'])->whereNumber(['driver', 'vehicle']);
        Route::patch('drivers/{driver}/vehicles/{vehicle}/status', [DriverController::class, 'vehicleStatus'])->whereNumber(['driver', 'vehicle']);
        Route::post('drivers/{driver}/vehicles/{vehicle}/activate', [DriverController::class, 'activateVehicle'])->whereNumber(['driver', 'vehicle']);

        // Multipart (the files).
        Route::post('drivers/{driver}/documents', [DriverController::class, 'storeDocument'])->whereNumber('driver');
        Route::patch('drivers/{driver}/documents/{document}/review', [DriverController::class, 'reviewDocument'])->whereNumber(['driver', 'document']);
        Route::delete('drivers/{driver}/documents/{document}', [DriverController::class, 'destroyDocument'])->whereNumber(['driver', 'document']);

        Route::get('driver-document-types/options', [DriverDocumentTypeController::class, 'options']);
        Route::get('driver-document-types', [DriverDocumentTypeController::class, 'index']);
        Route::post('driver-document-types', [DriverDocumentTypeController::class, 'store']);
        Route::put('driver-document-types/{documentType}', [DriverDocumentTypeController::class, 'update'])->whereNumber('documentType');
        Route::patch('driver-document-types/{documentType}/status', [DriverDocumentTypeController::class, 'status'])->whereNumber('documentType');
        Route::delete('driver-document-types/{documentType}', [DriverDocumentTypeController::class, 'destroy'])->whereNumber('documentType');
    });
});
