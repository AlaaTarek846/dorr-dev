<?php

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\AdminAuthController;
use Modules\Admin\Http\Controllers\AdminController;
use Modules\Admin\Http\Controllers\AdminProfileController;
use Modules\Admin\Http\Controllers\NotificationController;
use Modules\Admin\Http\Controllers\PermissionController;
use Modules\Admin\Http\Controllers\RoleController;

Route::middleware('locale')->prefix('admin/v1')->group(function () {
    require base_path('routes/admin.php');

    Route::middleware('guest:admin_api')->group(function () {
        Route::post('login', [AdminAuthController::class, 'login']);
        Route::post('check-token', [AdminAuthController::class, 'checkToken']);
    });

    Route::middleware(['auth:admin_api', 'remember-locale'])->group(function () {
        Route::get('me', [AdminAuthController::class, 'me']);
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::post('profile', [AdminProfileController::class, 'update']);
        Route::put('profile/password', [AdminProfileController::class, 'updatePassword']);

        Route::get('notifications/unread', [NotificationController::class, 'unread']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::get('notifications', [NotificationController::class, 'index']);

        Route::post('admins/delete-multiple', [AdminController::class, 'deleteMultiple']);
        Route::post('admins/{admin}/restore', [AdminController::class, 'restore']);
        Route::delete('admins/{admin}/force', [AdminController::class, 'forceDestroy']);
        Route::patch('admins/{admin}/status', [AdminController::class, 'changeStatus']);
        Route::apiResource('admins', AdminController::class)->names('admin');

        Route::post('roles/delete-multiple', [RoleController::class, 'deleteMultiple']);
        Route::apiResource('roles', RoleController::class);

        Route::post('permissions/delete-multiple', [PermissionController::class, 'deleteMultiple']);
        Route::apiResource('permissions', PermissionController::class);
    });
});
