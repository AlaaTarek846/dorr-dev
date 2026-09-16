<?php

use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\AiChatController;

Route::middleware(['locale', 'auth:user_api'])->prefix('user/v1/ai-chat')->group(function () {
    Route::get('status', [AiChatController::class, 'status']);

    Route::get('conversations', [AiChatController::class, 'index']);
    Route::post('conversations', [AiChatController::class, 'store']);
    Route::get('conversations/{conversation}', [AiChatController::class, 'show']);
    Route::delete('conversations/{conversation}', [AiChatController::class, 'destroy']);
    Route::post('conversations/{conversation}/messages', [AiChatController::class, 'sendMessage']);
});
