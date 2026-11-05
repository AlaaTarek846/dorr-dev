<?php

use Illuminate\Support\Facades\Route;
use Modules\Provider\Http\Controllers\App\DocumentController;
use Modules\Provider\Http\Controllers\App\EstablishmentController;
use Modules\Provider\Http\Controllers\App\MemberController;
use Modules\Provider\Http\Controllers\App\ProviderAppearanceController;
use Modules\Provider\Http\Controllers\App\ProviderPhoneAuthController;
use Modules\Provider\Http\Controllers\App\ProviderRealtimeController;
use Modules\Provider\Http\Controllers\App\VehicleController;
use Modules\Provider\Http\Controllers\App\WorkspaceController;

/*
| The providers app (docs/moving_tasks.md phase A). Phone sign-in, then the establishment the app works in, picked
| with the X-Establishment header (see EstablishmentAccess). The web portal's email routes stay in dashboard.php.
*/
Route::middleware('locale')->prefix('provider/v1')->group(function () {
    Route::middleware(['guest:provider_api', 'throttle:10,1'])->group(function () {
        Route::post('auth/otp', [ProviderPhoneAuthController::class, 'requestOtp']);
        Route::post('auth/verify', [ProviderPhoneAuthController::class, 'verifyOtp']);
        Route::post('auth/resend', [ProviderPhoneAuthController::class, 'resendOtp']);
    });

    Route::middleware(['auth:provider_api', 'ensure-phone-verified:provider_api', 'throttle:60,1', 'remember-locale'])->group(function () {
        Route::post('auth/logout', [ProviderPhoneAuthController::class, 'logout']);
        Route::get('realtime-config', ProviderRealtimeController::class);
        Route::get('appearance', ProviderAppearanceController::class);

        Route::get('workspace', [WorkspaceController::class, 'index']);
        Route::get('workspace/options', [WorkspaceController::class, 'options']);
        Route::post('invitations/{membership}/accept', [MemberController::class, 'accept']);
        Route::post('invitations/{membership}/decline', [MemberController::class, 'decline']);
        Route::post('memberships/{membership}/leave', [MemberController::class, 'leave']);

        Route::post('establishments', [EstablishmentController::class, 'store']);
        Route::get('establishment', [EstablishmentController::class, 'show']);
        Route::put('establishment', [EstablishmentController::class, 'update']);
        Route::post('establishment/submit', [EstablishmentController::class, 'submit']);

        Route::get('branches', [EstablishmentController::class, 'branches']);
        Route::post('branches', [EstablishmentController::class, 'storeBranch']);
        Route::put('branches/{branch}', [EstablishmentController::class, 'updateBranch']);
        Route::delete('branches/{branch}', [EstablishmentController::class, 'destroyBranch']);

        Route::get('members', [MemberController::class, 'index']);
        Route::post('members', [MemberController::class, 'store']);
        Route::put('members/{membership}', [MemberController::class, 'update']);
        Route::delete('members/{membership}', [MemberController::class, 'destroy']);
        Route::get('members/events', [MemberController::class, 'events']);

        Route::get('vehicles', [VehicleController::class, 'index']);
        Route::post('vehicles', [VehicleController::class, 'store']);
        Route::get('vehicles/{vehicle}', [VehicleController::class, 'show']);
        Route::put('vehicles/{vehicle}', [VehicleController::class, 'update']);
        Route::post('vehicles/{vehicle}/deactivate', [VehicleController::class, 'deactivate']);

        Route::get('documents', [DocumentController::class, 'index']);
        Route::post('documents', [DocumentController::class, 'store']);
    });
});
