<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\SupportSettingController;
use Modules\User\Http\Controllers\SupportTicketController;
use Modules\User\Http\Controllers\UserController;

Route::middleware(['locale', 'auth:admin_api'])->prefix('admin/v1')->group(function () {
    Route::post('users/delete-multiple', [UserController::class, 'deleteMultiple']);
    Route::post('users/{user}/restore', [UserController::class, 'restore']);
    Route::delete('users/{user}/force', [UserController::class, 'forceDestroy']);
    Route::patch('users/{user}/status', [UserController::class, 'changeStatus']);
    Route::apiResource('users', UserController::class);

    // Support tickets: the conversation with each customer, live.
    // Before {supportTicket}: the agents' "/" menu.
    Route::get('support-tickets/quick-replies', [SupportTicketController::class, 'quickReplies']);
    Route::get('support-tickets', [SupportTicketController::class, 'index']);
    Route::get('support-tickets/{supportTicket}', [SupportTicketController::class, 'show']);
    Route::get('support-tickets/{supportTicket}/messages', [SupportTicketController::class, 'messages']);
    Route::get('support-tickets/{supportTicket}/activities', [SupportTicketController::class, 'activities']);
    Route::post('support-tickets/{supportTicket}/messages', [SupportTicketController::class, 'sendMessage']);
    Route::patch('support-tickets/{supportTicket}/status', [SupportTicketController::class, 'status']);

    // Support settings: automatic replies (acknowledgement, away note, AI answers from the FAQs) + quick replies.
    Route::get('support-settings', [SupportSettingController::class, 'show']);
    Route::put('support-settings', [SupportSettingController::class, 'update']);
    Route::get('support-settings/quick-replies', [SupportSettingController::class, 'quickReplies']);
    Route::post('support-settings/quick-replies', [SupportSettingController::class, 'storeQuickReply']);
    Route::put('support-settings/quick-replies/{quickReply}', [SupportSettingController::class, 'updateQuickReply']);
    Route::delete('support-settings/quick-replies/{quickReply}', [SupportSettingController::class, 'destroyQuickReply']);
});
