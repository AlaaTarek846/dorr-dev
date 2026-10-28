<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * How the freelancer's app, website and dashboard connect to the real-time server and to push (FL-007) —
 * the same answer as the rider's and the captain's, with the freelancer's own private channel and the
 * freelancer app's OneSignal app id (its own if the owner set one, else the shared one). Served by the
 * API so moving to our own Reverb / Soketi needs no app update; asked once when the app opens.
 */
class FreelanceRealtimeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $driver = config('broadcasting.default');
        $connection = (array) config("broadcasting.connections.{$driver}", []);
        $options = (array) ($connection['options'] ?? []);
        $selfHosted = $driver === 'reverb' || ! str_ends_with((string) ($options['host'] ?? ''), 'pusher.com');
        $ownApp = config('services.onesignal.freelance_app_id') && config('services.onesignal.freelance_rest_api_key');

        return ApiResponse::success([
            'enabled' => in_array($driver, ['pusher', 'reverb'], true) && ! empty($connection['key']),
            'driver' => $driver,
            'key' => $connection['key'] ?? null,
            'cluster' => $options['cluster'] ?? null,
            'host' => $selfHosted ? ($options['host'] ?? null) : null,
            'port' => $selfHosted ? (int) ($options['port'] ?? 443) : null,
            'use_tls' => (bool) ($options['useTLS'] ?? true),
            'auth_path' => '/broadcasting/auth',
            // The freelancer's own channel: their notifications arrive on it.
            'channel' => 'private-Modules.Freelance.Models.Freelancer.'.$request->user('freelance_api')->getKey(),
            'onesignal_app_id' => ($ownApp ? config('services.onesignal.freelance_app_id') : config('services.onesignal.app_id')) ?: null,
        ], __('api.retrieved'));
    }
}
