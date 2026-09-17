<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\UserController;

Route::middleware(['locale', 'auth:admin_api'])->prefix('admin/v1')->group(function () {
    Route::post('users/delete-multiple', [UserController::class, 'deleteMultiple']);
    Route::post('users/{user}/restore', [UserController::class, 'restore']);
    Route::delete('users/{user}/force', [UserController::class, 'forceDestroy']);
    Route::patch('users/{user}/status', [UserController::class, 'changeStatus']);
    Route::apiResource('users', UserController::class);
});
