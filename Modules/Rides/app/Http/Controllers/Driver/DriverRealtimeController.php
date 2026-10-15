<?php

namespace Modules\Rides\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * How the captain app connects to the real-time server and to push — the same answer as the rider's
 * `chat/realtime-config`, but with the captain app's OneSignal app id (its own if the owner set one,
 * else the shared one). Served by the API so moving to our own Reverb / Soketi needs no app update.
 */
class DriverRealtimeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $driver = config('broadcasting.default');
        $connection = (array) config("broadcasting.connections.{$driver}", []);
        $options = (array) ($connection['options'] ?? []);
        $selfHosted = $driver === 'reverb' || ! str_ends_with((string) ($options['host'] ?? ''), 'pusher.com');
        $ownApp = config('services.onesignal.driver_app_id') && config('services.onesignal.driver_rest_api_key');

        return ApiResponse::success([
            'enabled' => in_array($driver, ['pusher', 'reverb'], true) && ! empty($connection['key']),
            'driver' => $driver,
            'key' => $connection['key'] ?? null,
            'cluster' => $options['cluster'] ?? null,
            'host' => $selfHosted ? ($options['host'] ?? null) : null,
            'port' => $selfHosted ? (int) ($options['port'] ?? 443) : null,
            'use_tls' => (bool) ($options['useTLS'] ?? true),
            'auth_path' => '/broadcasting/auth',
            // The captain's own private channel (requests, their rides, wallet and notifications all arrive on it).
            'channel' => 'private-Modules.Driver.Models.Driver.'.$request->user()->getKey(),
            'onesignal_app_id' => ($ownApp ? config('services.onesignal.driver_app_id') : config('services.onesignal.app_id')) ?: null,
        ], __('api.retrieved'));
    }
}
