<?php

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notifications for mobile accounts — /api/mobile/v1 (users), /api/provider/v1 (providers)
|--------------------------------------------------------------------------
|
| The same five endpoints for both audiences; the guard decides whose notifications they are.
| (The dashboard's own live in Modules/Admin/routes/admin.php.)
*/

$audiences = [
    'mobile/v1' => ['auth:user_api', 'ensure-phone-verified:user_api'],
    'provider/v1' => ['auth:provider_api'],
];

foreach ($audiences as $prefix => $auth) {
    Route::middleware('locale')->prefix($prefix)->group(function () use ($auth, $prefix) {
        Route::middleware([...$auth, 'remember-locale', 'throttle:60,1'])->group(function () use ($prefix) {
            $name = str_replace('/', '.', $prefix);

            Route::get('notifications', [NotificationController::class, 'index'])->name("{$name}.notifications.index");
            Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name("{$name}.notifications.unread-count");
            Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name("{$name}.notifications.read-all");
            Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name("{$name}.notifications.read");

            // Push: the phone registers / forgets its OneSignal player id.
            Route::post('notifications/devices', [NotificationController::class, 'registerDevice'])->name("{$name}.notifications.devices.store");
            Route::delete('notifications/devices', [NotificationController::class, 'unregisterDevice'])->name("{$name}.notifications.devices.destroy");
        });
    });
}
