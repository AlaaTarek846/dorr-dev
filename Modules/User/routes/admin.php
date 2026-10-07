<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\SupportTicketController;
use Modules\User\Http\Controllers\UserController;

Route::middleware(['locale', 'auth:admin_api'])->prefix('admin/v1')->group(function () {
    Route::post('users/delete-multiple', [UserController::class, 'deleteMultiple']);
    Route::post('users/{user}/restore', [UserController::class, 'restore']);
    Route::delete('users/{user}/force', [UserController::class, 'forceDestroy']);
    Route::patch('users/{user}/status', [UserController::class, 'changeStatus']);
    Route::apiResource('users', UserController::class);

    // Support tickets: the conversation with each customer, live.
    Route::get('support-tickets', [SupportTicketController::class, 'index']);
    Route::get('support-tickets/{supportTicket}', [SupportTicketController::class, 'show']);
    Route::get('support-tickets/{supportTicket}/messages', [SupportTicketController::class, 'messages']);
    Route::get('support-tickets/{supportTicket}/activities', [SupportTicketController::class, 'activities']);
    Route::post('support-tickets/{supportTicket}/messages', [SupportTicketController::class, 'sendMessage']);
    Route::patch('support-tickets/{supportTicket}/status', [SupportTicketController::class, 'status']);
});
