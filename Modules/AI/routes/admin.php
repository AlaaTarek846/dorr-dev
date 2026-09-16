<?php

use Illuminate\Support\Facades\Route;
use Modules\AI\Enums\AiProviderKey;
use Modules\AI\Http\Controllers\AiProviderController;

Route::middleware(['locale', 'auth:admin_api'])->prefix('admin/v1/ai-providers')->group(function () {
    Route::get('/', [AiProviderController::class, 'index']);

    Route::post('{provider}', [AiProviderController::class, 'update'])
        ->whereIn('provider', AiProviderKey::values());

    Route::post('{provider}/test', [AiProviderController::class, 'testConnection'])
        ->whereIn('provider', AiProviderKey::values());

    Route::post('{provider}/set-default', [AiProviderController::class, 'setDefault'])
        ->whereIn('provider', AiProviderKey::values());
});
