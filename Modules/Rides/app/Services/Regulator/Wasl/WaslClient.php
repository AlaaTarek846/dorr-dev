<?php

namespace Modules\Rides\Services\Regulator\Wasl;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Rides\Models\WaslLog;
use Throwable;

/**
 * The HTTP side of Wasl, as Jawad calls it (docs/rides-plan.md §11.1): its headers, a 15-second
 * timeout, no exception on 4xx/5xx, and every call written to `wasl_logs` (never the keys).
 */
class WaslClient
{
    public function configured(): bool
    {
        return filled(config('rides.wasl.app_id')) && filled(config('rides.wasl.app_key')) && filled(config('rides.wasl.client_id'));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: int, body: array<mixed>}
     */
    public function post(string $path, array $body, string $type, ?string $subjectType = null, ?int $subjectId = null): array
    {
        $started = microtime(true);
        try {
            $response = Http::withHeaders([
                'app_id' => (string) config('rides.wasl.app_id'),
                'app_key' => (string) config('rides.wasl.app_key'),
                'client-id' => (string) config('rides.wasl.client_id'),
            ])->acceptJson()->asJson()->timeout((int) config('rides.wasl.timeout', 15))
                ->post(config('rides.wasl.url').$path, $body);
            $result = ['status' => $response->status(), 'body' => (array) ($response->json() ?? [])];
        } catch (Throwable $e) {
            Log::warning('[Wasl] '.$type.' failed: '.$e->getMessage());
            $result = ['status' => 0, 'body' => ['resultCode' => 'CONNECTION_ERROR', 'resultMsg' => mb_substr($e->getMessage(), 0, 200)]];
        }

        WaslLog::query()->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'type' => $type,
            'request' => $body,
            'response' => $result['body'],
            'status_code' => $result['status'] ?: null,
            'success' => self::succeeded($result),
            'result_code' => self::resultCode($result),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'created_at' => now(),
        ]);

        return $result;
    }

    /** @param array{status: int, body: array<mixed>} $result */
    public static function succeeded(array $result): bool
    {
        $body = $result['body'];

        return $result['status'] === 200 && (! array_key_exists('success', $body) || (bool) $body['success']);
    }

    /** @param array{status: int, body: array<mixed>} $result */
    public static function resultCode(array $result): ?string
    {
        $body = $result['body'];
        $code = $body['resultCode'] ?? ($body[0]['resultCode'] ?? null);

        return $code === null ? null : mb_substr((string) $code, 0, 64);
    }

    /** Wasl's time format, in Riyadh time: 2026-10-14T13:05:09.000. */
    public static function time(?CarbonInterface $at): ?string
    {
        return $at?->copy()->setTimezone((string) config('rides.wasl.timezone', 'Asia/Riyadh'))->format('Y-m-d\TH:i:s.v');
    }
}
