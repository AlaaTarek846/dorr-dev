<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;

/**
 * How the app connects to the real-time server (docs/chat-plan.md §9.2). Served by the API
 * instead of built into the app, so moving from Pusher to our own Soketi / Reverb server later
 * reaches every phone without an app update. Only public values — never the secret.
 */
class RealtimeConfigController extends Controller
{
    public function __invoke()
    {
        $driver = config('broadcasting.default');
        $connection = (array) config("broadcasting.connections.{$driver}", []);
        $options = (array) ($connection['options'] ?? []);
        // Pusher's cloud hosts end in pusher.com; anything else is our own (Soketi / Reverb).
        $selfHosted = $driver === 'reverb' || ! str_ends_with((string) ($options['host'] ?? ''), 'pusher.com');

        return ApiResponse::success([
            'enabled' => in_array($driver, ['pusher', 'reverb'], true) && ! empty($connection['key']),
            'driver' => $driver,
            'key' => $connection['key'] ?? null,
            'cluster' => $options['cluster'] ?? null,
            // null = Pusher's own cloud (the client derives the host from the cluster).
            'host' => $selfHosted ? ($options['host'] ?? null) : null,
            'port' => $selfHosted ? (int) ($options['port'] ?? 443) : null,
            'use_tls' => (bool) ($options['useTLS'] ?? true),
            // Relative to the API's own host (the app already knows it).
            'auth_path' => '/broadcasting/auth',
        ], __('api.retrieved'));
    }
}
