<?php

use App\Models\Country;
use App\Notifications\GeneralNotification;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

if (! function_exists('responseJson')) {
    function responseJson(int $status, string $message, mixed $data = null, ?array $pagination = null): JsonResponse
    {
        return ApiResponse::json($status, $message, $data, $pagination);
    }
}

if (! function_exists('getPaginates')) {
    /**
     * @return array<string, mixed>
     */
    function getPaginates(LengthAwarePaginator $collection): array
    {
        return ApiPaginator::meta($collection);
    }
}

if (! function_exists('allOrPaginate')) {
    /**
     * @param  class-string<JsonResource>  $resource
     * @return array{data: mixed, pagination: array<string, mixed>|null}
     */
    function allOrPaginate(mixed $query, string $resource, ?string $groupBy = null): array
    {
        return ApiPaginator::resolve($query, $resource, $groupBy);
    }
}

if (! function_exists('currentCountry')) {
    /**
     * The country resolved for this request by the 'country' middleware
     * (App\Http\Middleware\ResolveCountryContext, App\Services\General\CountryResolver).
     * Null only if that middleware never ran on this request/route.
     */
    function currentCountry(): ?Country
    {
        return app()->bound('resolved_country') ? app('resolved_country') : null;
    }
}

if (! function_exists('getCountryCodeByIp')) {
    /**
     * Best-effort ISO alpha-2 country code (matches countries.code) for the
     * current request's IP: Cloudflare's CF-IPCountry when present, else a
     * free IP lookup (ipwho.is, then ip-api.com). Falls back to the seeded
     * default country — never a hardcoded literal — when geolocation fails
     * or resolves to a country we don't have active in the countries table.
     * The detected code is cached per IP for a day (a failed lookup for 10
     * minutes); "active" is checked on every call, so switching a country on
     * in the admin takes effect at once. The real IP behind a tunnel/proxy
     * needs TRUSTED_PROXIES (bootstrap/app.php).
     */
    function getCountryCodeByIp(): string
    {
        $ip = (string) request()->ip();

        $fallback = fn (): string => Country::query()->where('is_default', true)->value('code')
            ?? Country::query()->where('status', true)->value('code')
            ?? 'EG';

        $active = fn (?string $code): bool => $code && Country::query()->where('code', $code)->where('status', true)->exists();

        // Behind Cloudflare the edge already knows the visitor's country — no lookup needed.
        $edge = strtoupper((string) request()->header('CF-IPCountry'));
        if (strlen($edge) === 2 && $edge !== 'XX' && $edge !== 'T1') {
            return $active($edge) ? $edge : $fallback();
        }

        // A loopback / LAN address (local dev, or a proxy that isn't trusted — see TRUSTED_PROXIES)
        // can't be geolocated: answer the default without a lookup or caching it.
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $fallback();
        }

        $key = "country_code_by_ip:{$ip}";
        if (($cached = Cache::get($key)) !== null) {
            return $active($cached) ? $cached : $fallback();
        }

        // Free lookups tried in order (geoplugin.net went paid-only — it answers 403 now). Each is
        // [url, path of the ISO alpha-2 code in the JSON answer].
        $detected = null;
        foreach ([
            ["https://ipwho.is/{$ip}", 'country_code'],
            ["http://ip-api.com/json/{$ip}?fields=status,countryCode", 'countryCode'],
        ] as [$url, $path]) {
            try {
                $response = Http::timeout(3)->get($url);
                $code = $response->successful() ? $response->json($path) : null;
            } catch (Throwable) {
                $code = null;
            }
            if (is_string($code) && strlen($code) === 2) {
                $detected = strtoupper($code);
                break;
            }
        }

        // A real answer is kept for a day; a failed lookup only briefly, so it's retried soon.
        Cache::put($key, $detected ?? '', $detected ? now()->addDay() : now()->addMinutes(10));

        return $active($detected) ? $detected : $fallback();
    }
}

if (! function_exists('sendNotification')) {
    /**
     * Dispatch a GeneralNotification (database + Pusher broadcast) to one or many notifiables.
     *
     * @param  string  $event  Pusher event name, e.g. "order.updated"
     * @param  mixed  $receivers  Notifiable model, Eloquent Collection, or plain array of notifiables
     * @param  mixed  $data  Model or array attached to the broadcast payload
     * @param  string  $image  Full URL to the notification icon/image
     * @param  string|array  $title  Translation key (when $type=null) or locale-keyed array (when $type='raw')
     * @param  string|array  $message  Translation key (when $type=null) or locale-keyed array (when $type='raw')
     * @param  array  $variables  Replacement variables for translation placeholders
     * @param  string  $status  'Success' | 'Error'
     * @param  string|null  $type  null → use translation keys | 'raw' → use pre-translated arrays
     *
     * Example (translation key, multi-lang):
     *   sendNotification('order.placed', $user, $order, asset('logo.png'), 'order_placed_title', 'order_placed_body', ['order_no' => $order->number]);
     *
     * Example (raw / dashboard-sent, multi-lang):
     *   sendNotification('order.placed', $users, $order, '', ['ar' => 'العنوان', 'en' => 'Title', 'fr' => 'Titre'], [...], [], 'Success', 'raw');
     */
    function sendNotification(
        string $event,
        mixed $receivers,
        mixed $data,
        string $image,
        string|array $title,
        string|array $message,
        array $variables = [],
        string $status = 'Success',
        ?string $type = null,
    ): void {
        // Normalise to iterable
        if (! ($receivers instanceof Collection) && ! is_array($receivers)) {
            $receivers = [$receivers];
        }

        try {
            foreach ($receivers as $receiver) {
                $receiver?->notify(new GeneralNotification(
                    broadcastEvent: $event,
                    data: $data,
                    image: $image,
                    title: $title,
                    message: $message,
                    variables: $variables,
                    status: $status,
                    type: $type,
                ));
            }
        } catch (Throwable $e) {
            Log::error('[sendNotification] '.$e->getMessage(), [
                'event' => $event,
                'exception' => $e,
            ]);
        }
    }
}

if (! function_exists('sendPushNotification')) {
    /**
     * Send a push notification via OneSignal REST API.
     *
     * No extra Composer package is required — uses Laravel HTTP client.
     *
     * Required .env keys:
     *   ONESIGNAL_APP_ID
     *   ONESIGNAL_REST_API_KEY
     *   ONESIGNAL_GUZZLE_CLIENT_TIMEOUT   (default: 10)
     *
     * @param  string|array  $message  Notification body. Pass a string for a single locale,
     *                                 or a locale-keyed array for multi-lang:
     *                                 ['en' => 'Hello', 'ar' => 'مرحبا', 'fr' => 'Bonjour']
     * @param  string|array  $playerIds  OneSignal player ID(s) to target
     * @param  string|array  $title  Optional title. Same format as $message.
     * @param  array|null  $data  Extra key-value data forwarded to the device
     * @param  string|null  $image  Big picture / large icon URL
     * @param  string|null  $url  Deep-link or web URL to open on tap
     * @param  array|null  $buttons  Action buttons array (OneSignal format)
     * @param  string|null  $schedule  ISO-8601 datetime to schedule delivery (null = immediate)
     * @return bool true on success, false on failure
     *
     * Example (multi-lang):
     *   sendPushNotification(
     *       message: ['en' => 'Your order is ready', 'ar' => 'طلبك جاهز'],
     *       playerIds: $user->onesignal_player_id,
     *       title: ['en' => 'Order Update', 'ar' => 'تحديث الطلب'],
     *       data: ['order_id' => $order->id],
     *   );
     */
    function sendPushNotification(
        string|array $message,
        string|array $playerIds,
        string|array $title = '',
        ?array $data = null,
        ?string $image = null,
        ?string $url = null,
        ?array $buttons = null,
        ?string $schedule = null,
        array $options = [],
    ): bool {
        $appId = config('services.onesignal.app_id') ?: env('ONESIGNAL_APP_ID');
        $restApiKey = config('services.onesignal.rest_api_key') ?: env('ONESIGNAL_REST_API_KEY');
        $timeout = (int) env('ONESIGNAL_GUZZLE_CLIENT_TIMEOUT', 10);

        if (! $appId || ! $restApiKey) {
            Log::warning('[sendPushNotification] OneSignal credentials not configured.');

            return false;
        }

        // Normalise player IDs
        $ids = is_array($playerIds) ? $playerIds : [$playerIds];
        $ids = array_filter($ids); // drop null / empty
        if (empty($ids)) {
            return false;
        }

        // Normalise contents & headings to locale-keyed array
        $contents = is_array($message) ? $message : ['en' => $message];
        $headings = is_array($title) ? $title : ($title !== '' ? ['en' => $title] : null);

        $params = [
            'app_id' => $appId,
            'include_player_ids' => $ids,
            'contents' => $contents,
        ];

        if ($headings) {
            $params['headings'] = $headings;
        }

        if ($image) {
            $params['big_picture'] = $image;
            $params['large_icon'] = $image;
        }

        if ($url) {
            $params['url'] = $url;
        }

        if ($data) {
            $params['data'] = $data;
        }

        if ($buttons) {
            $params['buttons'] = $buttons;
        }

        if ($schedule) {
            $params['send_after'] = $schedule;
        }

        // Extra OneSignal fields for special pushes (e.g. an incoming call: priority 10, a short ttl).
        $params = array_merge($params, $options);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic '.$restApiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
                ->timeout($timeout)
                ->post('https://api.onesignal.com/notifications', $params);

            if ($response->failed()) {
                Log::error('[sendPushNotification] OneSignal request failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::error('[sendPushNotification] '.$e->getMessage(), ['exception' => $e]);

            return false;
        }
    }
}
