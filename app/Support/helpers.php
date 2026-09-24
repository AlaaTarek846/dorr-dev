<?php

use App\Models\Country;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resource
     * @return array{data: mixed, pagination: array<string, mixed>|null}
     */
    function allOrPaginate(mixed $query, string $resource, ?string $groupBy = null): array
    {
        return ApiPaginator::resolve($query, $resource, $groupBy);
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
                } catch (\Throwable) {
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
