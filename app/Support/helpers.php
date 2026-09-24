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
     * current request's IP, via geoplugin.net. Falls back to the seeded
     * default country — never a hardcoded literal — when geolocation fails
     * or resolves to a country we don't have active in the countries table.
     * Cached per IP for a day since geolocation-by-IP doesn't change often
     * and geoplugin.net has no SLA worth calling on every request.
     */
    function getCountryCodeByIp(): string
    {
        $ip = request()->ip();

        return Cache::remember(
            "country_code_by_ip:{$ip}",
            now()->addDay(),
            function () use ($ip): string {
                $fallback = Country::query()->where('is_default', true)->value('code')
                    ?? Country::query()->where('status', true)->value('code')
                    ?? 'EG';

                try {
                    $response = Http::timeout(3)->get('http://www.geoplugin.net/json.gp', ['ip' => $ip]);
                    $detected = $response->successful() ? $response->json('geoplugin_countryCode') : null;
                } catch (Throwable) {
                    $detected = null;
                }

                if (! $detected) {
                    return $fallback;
                }

                $isActiveCountry = Country::query()
                    ->where('code', $detected)
                    ->where('status', true)
                    ->exists();

                return $isActiveCountry ? $detected : $fallback;
            },
        );
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
