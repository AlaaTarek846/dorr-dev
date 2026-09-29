<?php

namespace Modules\Sms\Services\Sms\Adapters;

use Illuminate\Http\Client\Response;

/**
 * 4Jawaly (4jawaly.com) — Saudi SMS gateway.
 *
 * Authentication is HTTP Basic built from the account's API key + secret
 * (`base64("$apiKey:$apiSecret")`), not a bearer token. The v1 API exposes:
 *
 *   POST /account/area/sms/send          — send: {"messages":[{"text","numbers","sender"}]}
 *   GET  /account/area/senders           — approved sender names
 *   GET  /account/area/me/packages       — active packages (remaining points)
 *
 * The connection test deliberately probes `senders` (read-only) so verifying
 * credentials never sends a paid message.
 *
 * Recipients are posted in bare international form (`966500000000`) matching
 * the vendor's documented examples, so a leading `+` from the E.164
 * normalizer is stripped.
 *
 * The account works in points rather than a cash balance: `getBalance()`
 * reports the summed `current_points` of the active packages, with `points`
 * as the currency.
 */
class FourJawalySmsAdapter extends BaseSmsAdapter
{
    protected const BASE_URL = 'https://api-sms.4jawaly.com/api/v1';

    public function key(): string
    {
        return 'four_jawaly';
    }

    public function label(): string
    {
        return '4Jawaly';
    }

    public function configurationSchema(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'text', 'required' => true, 'secret' => true],
            ['key' => 'api_secret', 'label' => 'API Secret', 'type' => 'password', 'required' => true, 'secret' => true],
            ['key' => 'sender', 'label' => 'Sender Name', 'type' => 'text', 'required' => true, 'secret' => false],
        ];
    }

    public function capabilities(): array
    {
        return ['send_sms', 'send_bulk', 'balance', 'sender_ids', 'sender_approval'];
    }

    /**
     * Basic auth header value derived from the API key + secret pair.
     */
    protected function basicAuth(array $config): string
    {
        return 'Basic '.base64_encode(
            ($config['api_key'] ?? '').':'.($config['api_secret'] ?? ''),
        );
    }

    /**
     * 4Jawaly's JSON endpoints require this explicit header trio; the Laravel
     * client's default JSON handling does not set `Authorization` for us.
     *
     * @return array<string, string>
     */
    protected function headers(array $config): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => $this->basicAuth($config),
        ];
    }

    /**
     * Strip a leading `+` and any formatting so the number matches the
     * vendor's bare international format.
     */
    protected function formatRecipient(string $number): string
    {
        return preg_replace('/\D+/', '', $number) ?: '';
    }

    public function testConnection(array $config): array
    {
        if (empty($config['api_key']) || empty($config['api_secret'])) {
            return ['success' => false, 'message' => $this->translateRequired()];
        }

        try {
            // Read-only probe: lists approved senders without sending anything.
            $res = $this->http()
                ->withHeaders($this->headers($config))
                ->get(self::BASE_URL.'/account/area/senders', [
                    'page_size' => 10,
                    'page' => 1,
                    'status' => 1,
                    'return_collection' => 1,
                ]);

            if ($res->successful()) {
                return ['success' => true, 'message' => $this->translateSuccess()];
            }

            return [
                'success' => false,
                'message' => $this->normalizeHttpError(
                    $res->status(),
                    fn () => $this->extractErrorMessage($res),
                    '4Jawaly connection failed',
                ),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $this->normalizeError($e)];
        }
    }

    public function getBalance(array $config): array
    {
        try {
            $res = $this->http()
                ->withHeaders($this->headers($config))
                ->get(self::BASE_URL.'/account/area/me/packages', [
                    'is_active' => 1,
                    'order_by' => 'id',
                    'order_by_type' => 'desc',
                    'page' => 1,
                    'page_size' => 10,
                    'return_collection' => 1,
                ]);

            if (! $res->successful()) {
                return [
                    'success' => false,
                    'message' => $this->normalizeHttpError(
                        $res->status(),
                        fn () => $this->extractErrorMessage($res),
                        '4Jawaly balance failed',
                    ),
                    'balance' => null,
                    'currency' => null,
                ];
            }

            $balance = $this->sumCurrentPoints($res);

            return [
                'success' => true,
                'message' => '4Jawaly balance retrieved',
                'balance' => $balance,
                'currency' => 'points',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $this->normalizeError($e),
                'balance' => null,
                'currency' => null,
            ];
        }
    }

    public function getSenderIds(array $config): array
    {
        try {
            $res = $this->http()
                ->withHeaders($this->headers($config))
                ->get(self::BASE_URL.'/account/area/senders', [
                    'page_size' => 500,
                    'page' => 1,
                    'status' => 1,
                    'return_collection' => 1,
                ]);

            if (! $res->successful()) {
                return [
                    'success' => false,
                    'message' => $this->normalizeHttpError(
                        $res->status(),
                        fn () => $this->extractErrorMessage($res),
                        '4Jawaly senders lookup failed',
                    ),
                    'senders' => [],
                ];
            }

            $senders = [];

            foreach ($this->collection($res) as $item) {
                if (is_array($item) && ! empty($item['sender_name'])) {
                    $senders[] = (string) $item['sender_name'];
                }
            }

            return [
                'success' => true,
                'message' => 'Approved senders retrieved',
                'senders' => array_values(array_unique($senders)),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $this->normalizeError($e), 'senders' => []];
        }
    }

    public function send(array $config, array $payload): array
    {
        $to = $this->formatRecipient((string) ($payload['to'] ?? ''));
        $message = (string) ($payload['message'] ?? '');
        $sender = ($payload['from'] ?? null) ?: ($config['sender'] ?? null);

        if ($to === '') {
            return ['success' => false, 'message' => 'Recipient phone number is required'];
        }

        if ($message === '') {
            return ['success' => false, 'message' => 'Message body is required'];
        }

        // 4Jawaly has no sandbox flag on the v1 send endpoint; a test send is
        // flagged for the caller but cannot be intercepted here.
        $testOnly = ! empty($payload['test_only']);

        try {
            $res = $this->http()
                ->withHeaders($this->headers($config))
                ->post(self::BASE_URL.'/account/area/sms/send', [
                    'messages' => [
                        [
                            'text' => $message,
                            'numbers' => [$to],
                            'sender' => $sender,
                        ],
                    ],
                ]);

            if (! $res->successful()) {
                return [
                    'success' => false,
                    'message' => $this->normalizeHttpError(
                        $res->status(),
                        fn () => $this->extractErrorMessage($res),
                        '4Jawaly send failed',
                    ),
                    'test_only' => $testOnly,
                ];
            }

            $json = $res->json();
            $first = is_array($json) ? ($json['messages'][0] ?? null) : null;

            // HTTP 200 can still carry a per-message error.
            if (is_array($first) && ! empty($first['err_text'])) {
                return [
                    'success' => false,
                    'message' => $this->stripSensitive((string) $first['err_text']),
                    'test_only' => $testOnly,
                ];
            }

            // Partial failures are reported per number.
            $failed = is_array($first['error_numbers'] ?? null) ? $first['error_numbers'] : [];

            if (! empty($failed)) {
                $firstError = $failed[0]['error'] ?? 'unknown error';

                return [
                    'success' => false,
                    'message' => $this->stripSensitive((string) $firstError),
                    'test_only' => $testOnly,
                ];
            }

            return [
                'success' => true,
                'message' => 'SMS sent via 4Jawaly',
                'provider_message_id' => isset($json['job_id']) ? (string) $json['job_id'] : null,
                'test_only' => $testOnly,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $this->normalizeError($e), 'test_only' => $testOnly];
        }
    }

    public function normalizeError(\Throwable|\Exception|string|array $error): string
    {
        if (is_array($error)) {
            $message = $error['message'] ?? $error['error'] ?? json_encode($error);

            return $this->stripSensitive((string) $message);
        }

        return $this->stripSensitive((string) ($error instanceof \Throwable ? $error->getMessage() : $error));
    }

    /**
     * The vendor returns either `items` (senders) or `collection` (packages);
     * accept both shapes.
     *
     * @return list<array<string, mixed>>
     */
    protected function collection(Response $res): array
    {
        $json = $res->json();

        if (! is_array($json)) {
            return [];
        }

        foreach (['items', 'collection'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                return array_values(array_filter($json[$key], 'is_array'));
            }
        }

        return [];
    }

    /**
     * Sum the remaining `current_points` of the active packages.
     */
    protected function sumCurrentPoints(Response $res): int|float
    {
        $total = 0;

        foreach ($this->collection($res) as $item) {
            $total += (float) ($item['current_points'] ?? 0);
        }

        return $total;
    }

    /**
     * Pull a human-readable message out of an error response body.
     */
    protected function extractErrorMessage(Response $res): string
    {
        $json = $res->json();

        if (is_array($json)) {
            foreach (['message', 'error', 'error_description'] as $key) {
                if (is_string($json[$key] ?? null) && $json[$key] !== '') {
                    return $json[$key];
                }
            }
        }

        return trim((string) $res->body());
    }

    protected function translateRequired(): string
    {
        return (string) trans('sms.providers.test_errors.required_credentials', ['provider' => '4Jawaly'], app()->getLocale());
    }

    protected function translateSuccess(): string
    {
        return (string) trans('sms.providers.connection_successful', [], app()->getLocale());
    }
}
