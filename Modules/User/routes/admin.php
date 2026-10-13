<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\SecurityAdminController;
use Modules\User\Http\Controllers\SupportHelpNodeController;
use Modules\User\Http\Controllers\SupportQuickReplyController;
use Modules\User\Http\Controllers\SupportSettingController;
use Modules\User\Http\Controllers\SupportTicketController;
use Modules\User\Http\Controllers\UserController;
use Modules\User\Http\Controllers\UserNotificationController;

Route::middleware(['locale', 'auth:admin_api'])->prefix('admin/v1')->group(function () {
    Route::post('users/delete-multiple', [UserController::class, 'deleteMultiple']);
    // A message from the dashboard to one customer, a chosen group or all of them (in-app + real-time + push).
    Route::post('users/notify', [UserNotificationController::class, 'store']);
    Route::get('users/notify/audience', [UserNotificationController::class, 'audience']);
    Route::post('users/{user}/restore', [UserController::class, 'restore']);
    Route::delete('users/{user}/force', [UserController::class, 'forceDestroy']);
    Route::patch('users/{user}/status', [UserController::class, 'changeStatus']);
    // A customer's signed-in devices; support can sign one or all of them out.
    Route::get('users/{user}/sessions', [SecurityAdminController::class, 'sessions']);
    Route::delete('users/{user}/sessions/{session}', [SecurityAdminController::class, 'signOut'])->whereNumber('session');
    Route::post('users/{user}/sessions/logout-all', [SecurityAdminController::class, 'signOutAll']);
    Route::apiResource('users', UserController::class);

    // Security log of every customer (sign-ins, failed codes, devices signed out, PIN locks…).
    Route::get('security-events', [SecurityAdminController::class, 'events']);
    Route::get('security-events/{event}', [SecurityAdminController::class, 'event'])->whereNumber('event');

    // Support tickets: the conversation with each customer, live.
    // Before {supportTicket}: the agents' "/" menu.
    Route::get('support-tickets/quick-replies', [SupportTicketController::class, 'quickReplies']);
    Route::get('support-tickets', [SupportTicketController::class, 'index']);
    Route::get('support-tickets/{supportTicket}', [SupportTicketController::class, 'show']);
    Route::get('support-tickets/{supportTicket}/messages', [SupportTicketController::class, 'messages']);
    Route::get('support-tickets/{supportTicket}/activities', [SupportTicketController::class, 'activities']);
    Route::post('support-tickets/{supportTicket}/messages', [SupportTicketController::class, 'sendMessage']);
    Route::patch('support-tickets/{supportTicket}/status', [SupportTicketController::class, 'status']);

    // Support settings: automatic replies (acknowledgement, away note, AI answers from the FAQs).
    Route::get('support-settings', [SupportSettingController::class, 'show']);
    Route::put('support-settings', [SupportSettingController::class, 'update']);

    // The guided help menu of the app (a tree of topics).
    Route::get('support-help-nodes/options', [SupportHelpNodeController::class, 'options']);
    Route::patch('support-help-nodes/{supportHelpNode}/status', [SupportHelpNodeController::class, 'status']);
    Route::apiResource('support-help-nodes', SupportHelpNodeController::class);

    // Quick replies: the ready answers agents drop in with "/".
    Route::post('support-quick-replies/delete-multiple', [SupportQuickReplyController::class, 'deleteMultiple']);
    Route::patch('support-quick-replies/{supportQuickReply}/status', [SupportQuickReplyController::class, 'status']);
    Route::apiResource('support-quick-replies', SupportQuickReplyController::class);
});
