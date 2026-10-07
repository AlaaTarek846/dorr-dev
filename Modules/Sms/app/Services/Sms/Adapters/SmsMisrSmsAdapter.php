<?php

namespace Modules\Sms\Services\Sms\Adapters;

use Illuminate\Http\Client\Response;

/**
 * SMS Misr (smsmisr.com) — Egyptian provider. Username + Password + Sender.
 * 'environment' => live|test switches between the Test (2) and Live (1) API
 * environments. The connection test ALWAYS probes the test environment with a
 * placeholder recipient — a live SMS is never sent by Test Connection.
 */
class SmsMisrSmsAdapter extends BaseSmsAdapter
{
    public function key(): string
    {
        return 'sms_misr';
    }

    public function label(): string
    {
        return 'SMS Misr';
    }

    public function configurationSchema(): array
    {
        return [
            ['key' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true, 'secret' => true],
            ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'secret' => true],
            ['key' => 'sender', 'label' => 'Sender Name', 'type' => 'text', 'required' => true, 'secret' => false],
            ['key' => 'environment', 'label' => 'Environment', 'type' => 'select', 'required' => false, 'secret' => false, 'options' => ['live' => 'Live', 'test' => 'Test']],
        ];
    }

    public function capabilities(): array
    {
        return ['send_sms', 'send_bulk', 'test_mode', 'sender_approval'];
    }

    protected function isTestEnvironment(array $config): bool
    {
        $env = $config['environment'] ?? 'live';

        // Accept both the string form ('live'/'test') and the int form (1/2).
        return $env === 'test' || $env === 2 || $env === '2';
    }

    /**
     * Resolve the configured environment to SMS Misr's integer API value
     * (1 = Live, 2 = Test).
     */
    protected function environmentInt(array $config): int
    {
        return $this->isTestEnvironment($config) ? 2 : 1;
    }

    /**
     * SMS Misr returns a JSON object on success ({"code":"1901",...}) but may
     * return a bare numeric body on older endpoints. Parse either form.
     */
    protected function extractCode(Response $res): int
    {
        $body = trim((string) $res->body());

        // Try JSON first ({"code": "1901", ...}).
        if (str_starts_with($body, '{') || str_starts_with($body, '[')) {
            $json = json_decode($body, true);
            if (is_array($json)) {
                $code = $json['code'] ?? $json['Value1'] ?? $json['status'] ?? null;
                if ($code !== null) {
                    return (int) $code;
                }
            }
        }

        // Fall back to a bare numeric body ("1901").
        return (int) $body;
    }

    public function testConnection(array $config): array
    {
        if (empty($config['username']) || empty($config['password'])) {
            return ['success' => false, 'message' => $this->translateRequired()];
        }

        // Probe the TEST environment (2) with a placeholder recipient: the
        // provider validates credentials/sender without delivering anything.
        $probe = array_merge($config, ['environment' => 'test']);

        try {
            $res = $this->http()->asForm()->post('https://smsmisr.com/api/SMS/', [
                'environment' => 2,
                'username' => $probe['username'],
                'password' => $probe['password'],
                'language' => 1,
                'mobile' => '+201000000000',
                'sender' => $probe['sender'] ?? '',
                'message' => 'CRM connection test',
            ]);

            $code = $this->extractCode($res);

            if ($res->successful() && $code === 1901) {
                return ['success' => true, 'message' => $this->translateSuccess($code)];
            }

            return ['success' => false, 'message' => $this->mapMisrCode($code)];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $this->normalizeError($e)];
        }
    }

    public function getBalance(array $config): array
    {
        // SMS Misr exposes no public balance endpoint on the standard API;
        // the connection probe is the supported verification path.
        return ['success' => false, 'message' => 'Balance lookup not supported for SMS Misr', 'balance' => null, 'currency' => null];
    }

    public function getSenderIds(array $config): array
    {
        // SMS Misr requires Sender ID approval; it exposes no public
        // sender-list API to the CRM. Surface the configured sender only.
        return ['success' => true, 'message' => 'Configured sender (subject to approval)', 'senders' => array_filter([$config['sender'] ?? null])];
    }

    public function send(array $config, array $payload): array
    {
        $to = $payload['to'] ?? null;
        $message = $payload['message'] ?? '';
        if (! $to || $message === '') {
            return ['success' => false, 'message' => 'Recipient and message are required'];
        }

        $testEnv = $this->isTestEnvironment($config);
        $testOnly = $testEnv || ! empty($payload['test_only']);

        try {
            $res = $this->http()->asForm()->post('https://smsmisr.com/api/SMS/', [
                'environment' => $testOnly ? 2 : $this->environmentInt($config),
                'username' => $config['username'] ?? '',
                'password' => $config['password'] ?? '',
                'language' => 1,
                'mobile' => $to,
                'sender' => $payload['from'] ?: ($config['sender'] ?? null),
                'message' => $message,
            ]);

            $code = $this->extractCode($res);

            if ($res->successful() && $code === 1901) {
                return ['success' => true, 'message' => 'SMS sent via SMS Misr', 'provider_message_id' => (string) $code, 'test_only' => $testOnly];
            }

            return ['success' => false, 'message' => $this->mapMisrCode($code), 'test_only' => $testOnly];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $this->normalizeError($e), 'test_only' => $testOnly];
        }
    }

    protected function mapMisrCode(int $code): string
    {
        // SMS Misr's current response codes (success 1901; errors 1902-1912).
        $map = [
            1901 => 'sent',
            1902 => 'invalid_request',
            1903 => 'invalid_credentials',
            1904 => 'invalid_sender',
            1905 => 'invalid_mobile',
            1906 => 'insufficient_balance',
            1907 => 'server_updating',
            1908 => 'invalid_delay',
            1909 => 'invalid_message',
            1910 => 'invalid_language',
            1911 => 'message_too_long',
            1912 => 'invalid_environment',
        ];

        $key = $map[$code] ?? null;

        if (! $key) {
            return trans('sms.providers.test_errors.sms_misr.generic', ['code' => $code], app()->getLocale());
        }

        return trans('sms.providers.test_errors.sms_misr.'.$key, [], app()->getLocale());
    }

    protected function translateSuccess(int $code): string
    {
        return 'SMS Misr credentials valid (test environment, response code '.$code.')';
    }

    protected function translateRequired(): string
    {
        return trans('sms.providers.test_errors.required_credentials', ['provider' => 'SMS Misr'], app()->getLocale());
    }

    public function normalizeError(\Throwable|\Exception|string|array $error): string
    {
        if (is_array($error)) {
            return $this->stripSensitive((string) ($error['message'] ?? json_encode($error)));
        }

        return $this->stripSensitive((string) ($error instanceof \Throwable ? $error->getMessage() : $error));
    }
}
