<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\AdminAuthController;
use Modules\Admin\Http\Controllers\AdminController;
use Modules\Admin\Http\Controllers\AdminProfileController;

Route::middleware('locale')->prefix('admin/v1')->group(function () {
    require base_path('routes/admin.php');

    Route::middleware('guest:admin_api')->group(function () {
        Route::post('login', [AdminAuthController::class, 'login']);
        Route::post('check-token', [AdminAuthController::class, 'checkToken']);
    });

    Route::middleware('auth:admin_api')->group(function () {
        Route::get('me', [AdminAuthController::class, 'me']);
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::post('profile', [AdminProfileController::class, 'update']);
        Route::put('profile/password', [AdminProfileController::class, 'updatePassword']);

        Route::post('admins/delete-multiple', [AdminController::class, 'deleteMultiple']);
        Route::post('admins/{admin}/restore', [AdminController::class, 'restore']);
        Route::delete('admins/{admin}/force', [AdminController::class, 'forceDestroy']);
        Route::patch('admins/{admin}/status', [AdminController::class, 'changeStatus']);
        Route::apiResource('admins', AdminController::class)->names('admin');
    });
});
