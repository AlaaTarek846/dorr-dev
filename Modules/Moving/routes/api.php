<?php

use Illuminate\Support\Facades\Route;
use Modules\Moving\Http\Controllers\Admin\MovingCaseController;
use Modules\Moving\Http\Controllers\Admin\MovingPolicyController;
use Modules\Moving\Http\Controllers\Admin\MovingRequestAdminController;
use Modules\Moving\Http\Controllers\Admin\MovingTicketAdminController;
use Modules\Moving\Http\Controllers\Customer\MovingAfterController;
use Modules\Moving\Http\Controllers\Customer\MovingRequestController;
use Modules\Moving\Http\Controllers\Provider\MovingProviderAfterController;
use Modules\Moving\Http\Controllers\Provider\MovingProviderController;

// The customer, in the Dorr app (docs/moving_tasks.md B-02 → B-06).
Route::middleware('locale')->prefix('mobile/v1/moving')->group(function () {
    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api', 'throttle:120,1', 'country', 'remember-locale'])->group(function () {
        Route::get('options', [MovingRequestController::class, 'options']);
        Route::get('route', [MovingRequestController::class, 'route']);
        Route::get('requests', [MovingRequestController::class, 'index']);
        Route::post('requests', [MovingRequestController::class, 'store']);
        Route::get('requests/{move}', [MovingRequestController::class, 'show']);
        Route::put('requests/{move}', [MovingRequestController::class, 'update']);
        Route::post('requests/{move}/media', [MovingRequestController::class, 'addMedia'])->middleware('throttle:30,1,moving-media');
        Route::delete('requests/{move}/media/{media}', [MovingRequestController::class, 'removeMedia'])->whereNumber('media');
        Route::post('requests/{move}/publish', [MovingRequestController::class, 'publish']);
        Route::post('requests/{move}/withdraw', [MovingRequestController::class, 'withdraw']);
        Route::post('requests/{move}/republish', [MovingRequestController::class, 'republish']);
        Route::get('requests/{move}/offers', [MovingRequestController::class, 'offers']);
        Route::post('requests/{move}/offers/{offer}/accept', [MovingRequestController::class, 'accept']);
        Route::get('requests/{move}/cancellation', [MovingRequestController::class, 'cancellationTerms']);
        Route::post('requests/{move}/cancel', [MovingRequestController::class, 'cancel']);
        Route::post('requests/{move}/confirm', [MovingRequestController::class, 'confirmReceipt']);
        Route::post('requests/{move}/report', [MovingRequestController::class, 'report'])->middleware('throttle:10,1,moving-report');
        // Changes, new times, rating, invoice and tickets (B-13, B-15, B-06).
        Route::get('requests/{move}/changes', [MovingAfterController::class, 'changes']);
        Route::post('requests/{move}/changes/{change}/respond', [MovingAfterController::class, 'respond']);
        Route::post('requests/{move}/reschedule', [MovingAfterController::class, 'reschedule']);
        Route::post('requests/{move}/rating', [MovingAfterController::class, 'rate']);
        Route::get('requests/{move}/invoice', [MovingAfterController::class, 'invoice']);
        Route::get('requests/{move}/tickets', [MovingAfterController::class, 'tickets']);
        Route::post('requests/{move}/tickets', [MovingAfterController::class, 'openTicket'])->middleware('throttle:10,1,moving-ticket');
        Route::get('tickets/{ticket}', [MovingAfterController::class, 'showTicket']);
        Route::post('tickets/{ticket}/act', [MovingAfterController::class, 'actOnTicket']);
    });
});

// The providers app (B-03, B-04, B-07). The establishment comes from the X-Establishment header.
Route::middleware('locale')->prefix('provider/v1/moving')->group(function () {
    Route::middleware(['auth:provider_api', 'ensure-phone-verified:provider_api', 'throttle:120,1', 'remember-locale'])->group(function () {
        Route::get('dashboard', [MovingProviderController::class, 'dashboard']);
        Route::get('requests', [MovingProviderController::class, 'requests']);
        Route::get('requests/{move}', [MovingProviderController::class, 'showRequest']);
        Route::post('requests/{move}/offers', [MovingProviderController::class, 'sendOffer']);
        Route::get('offers', [MovingProviderController::class, 'myOffers']);
        Route::put('offers/{offer}', [MovingProviderController::class, 'updateOffer']);
        Route::post('offers/{offer}/withdraw', [MovingProviderController::class, 'withdrawOffer']);
        Route::post('offers/{offer}/approve', [MovingProviderController::class, 'approveOffer']);
        Route::get('jobs', [MovingProviderController::class, 'jobs']);
        Route::get('drivers', [MovingProviderController::class, 'drivers']);
        Route::get('jobs/{move}/changes', [MovingProviderController::class, 'changes']);
        Route::post('jobs/{move}/assign', [MovingProviderController::class, 'assign']);
        Route::get('my-jobs', [MovingProviderController::class, 'myJobs']);
        Route::post('jobs/{move}/accept', [MovingProviderController::class, 'acceptJob']);
        Route::post('jobs/{move}/decline', [MovingProviderController::class, 'declineJob']);
        // Moving day (B-08 → B-10).
        Route::post('jobs/{move}/step', [MovingProviderController::class, 'step']);
        Route::post('jobs/{move}/locations', [MovingProviderController::class, 'locations'])->middleware('throttle:240,1,moving-locations');
        Route::get('jobs/{move}/inventory', [MovingProviderController::class, 'inventory']);
        Route::post('jobs/{move}/inventory', [MovingProviderController::class, 'addItem']);
        Route::post('jobs/{move}/inventory/{item}/delivered', [MovingProviderController::class, 'deliverItem']);
        Route::post('jobs/{move}/deliver', [MovingProviderController::class, 'deliver'])->middleware('throttle:20,1,moving-deliver');
        Route::post('jobs/{move}/cancel', [MovingProviderController::class, 'cancel']);
        Route::post('jobs/{move}/changes', [MovingProviderAfterController::class, 'proposeChange']);
        Route::post('jobs/{move}/reschedule', [MovingProviderAfterController::class, 'reschedule']);
        Route::post('changes/{change}/respond', [MovingProviderAfterController::class, 'respond']);
        Route::get('jobs/{move}/invoice', [MovingProviderAfterController::class, 'invoice']);
        Route::get('tickets', [MovingProviderAfterController::class, 'tickets']);
        Route::get('tickets/{ticket}', [MovingProviderAfterController::class, 'showTicket']);
        Route::post('tickets/{ticket}/reply', [MovingProviderAfterController::class, 'reply']);
    });
});

// The admin (B-17): the values each country sets for moving.
Route::middleware(['locale', 'auth:admin_api'])->prefix('admin/v1')->group(function () {
    Route::get('moving-policies', [MovingPolicyController::class, 'index']);
    Route::put('moving-policies', [MovingPolicyController::class, 'update']);
    Route::get('moving-cases', [MovingCaseController::class, 'index']);
    Route::get('moving-cases/{case}', [MovingCaseController::class, 'show']);
    Route::post('moving-cases/{case}/decide', [MovingCaseController::class, 'decide']);
    Route::get('moving-requests/summary', [MovingRequestAdminController::class, 'summary']);
    Route::get('moving-requests', [MovingRequestAdminController::class, 'index']);
    Route::get('moving-requests/{move}', [MovingRequestAdminController::class, 'show']);
    Route::get('moving-tickets', [MovingTicketAdminController::class, 'index']);
    Route::get('moving-tickets/{ticket}', [MovingTicketAdminController::class, 'show']);
    Route::post('moving-tickets/{ticket}/act', [MovingTicketAdminController::class, 'act']);
});
