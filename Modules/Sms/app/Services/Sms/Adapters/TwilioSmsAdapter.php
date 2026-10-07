<?php

namespace Modules\Sms\Services\Sms\Adapters;

/**
 * Twilio — REST API credentials (Account SID + Auth Token). Supports the
 * account balance endpoint for connection tests and the Messages resource
 * for sending. In test mode it points at the sandbox number to avoid a
 * real send.
 */
class TwilioSmsAdapter extends BaseSmsAdapter
{
    public function key(): string
    {
        return 'twilio';
    }

    public function label(): string
    {
        return 'Twilio';
    }

    public function configurationSchema(): array
    {
        return [
            ['key' => 'account_sid', 'label' => 'Account SID', 'type' => 'text', 'required' => true, 'secret' => true],
            ['key' => 'auth_token', 'label' => 'Auth Token', 'type' => 'password', 'required' => true, 'secret' => true],
            ['key' => 'from', 'label' => 'From (number or Messaging Service SID)', 'type' => 'text', 'required' => true, 'secret' => false],
            ['key' => 'test_mode', 'label' => 'Test mode (sandbox)', 'type' => 'boolean', 'required' => false, 'secret' => false],
            ['key' => 'test_phone_number', 'label' => 'Sandbox phone (test mode only)', 'type' => 'text', 'required' => false, 'secret' => false],
        ];
    }

    public function capabilities(): array
    {
        return ['send_sms', 'send_bulk', 'balance', 'test_mode', 'delivery_reports', 'sender_ids'];
    }

    protected function baseUri(array $config): string
    {
        return 'https://api.twilio.com/2010-04-01/Accounts/'.($config['account_sid'] ?? '').'/';
    }

    public function testConnection(array $config): array
    {
        $sid = $config['account_sid'] ?? null;
        $token = $config['auth_token'] ?? null;
        if (! $sid || ! $token) {
            return ['success' => false, 'message' => 'Twilio Account SID and Auth Token are required'];
        }

        try {
            $res = $this->http()->withBasicAuth($sid, $token)->get($this->baseUri($config).'Balance.json');

            if ($res->status() === 200) {
                $json = $res->json();
                $balance = $json['balance'] ?? null;
                $msg = $balance !== null
                    ? ('Twilio credentials valid. Balance: '.$balance.' '.($json['currency'] ?? 'USD'))
                    : 'Twilio credentials are valid';

                return ['success' => true, 'message' => $msg, 'balance' => $balance, 'currency' => $json['currency'] ?? null];
            }

            return ['success' => false, 'message' => $this->normalizeHttpError($res->status(), fn () => (string) $res->body(), 'Twilio connection failed')];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $this->normalizeError($e)];
        }
    }

    public function getBalance(array $config): array
    {
        try {
            $res = $this->http()->withBasicAuth($config['account_sid'] ?? '', $config['auth_token'] ?? '')
                ->get($this->baseUri($config).'Balance.json');

            if ($res->status() === 200) {
                $json = $res->json();

                return ['success' => true, 'message' => 'Balance retrieved', 'balance' => $json['balance'] ?? null, 'currency' => $json['currency'] ?? null];
            }

            return ['success' => false, 'message' => $this->normalizeHttpError($res->status(), fn () => (string) $res->body(), 'Twilio balance failed'), 'balance' => null, 'currency' => null];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $this->normalizeError($e), 'balance' => null, 'currency' => null];
        }
    }

    public function getSenderIds(array $config): array
    {
        // The configured "from" is the chosen sender (number or Messaging
        // Service SID).
        return ['success' => true, 'message' => 'Configured sender', 'senders' => array_filter([$config['from'] ?? null])];
    }

    public function send(array $config, array $payload): array
    {
        $from = $payload['from'] ?: ($config['from'] ?? null);
        $to = $payload['to'] ?? null;
        $message = $payload['message'] ?? '';

        if (! $to) {
            return ['success' => false, 'message' => 'Recipient phone number is required'];
        }
        if ($message === '') {
            return ['success' => false, 'message' => 'Message body is required'];
        }

        $testOnly = $this->isTestModeEnabled($config) || ! empty($payload['test_only']);
        // In test mode, target the sandbox number so nothing live is sent.
        $destination = ($testOnly && ! empty($config['test_phone_number'])) ? $config['test_phone_number'] : $to;

        try {
            $res = $this->http()->withBasicAuth($config['account_sid'] ?? '', $config['auth_token'] ?? '')
                ->asForm()
                ->post($this->baseUri($config).'Messages.json', [
                    'From' => $from,
                    'To' => $destination,
                    'Body' => $message,
                ]);

            if ($res->status() === 201) {
                $json = $res->json();

                return [
                    'success' => true,
                    'message' => 'SMS sent via Twilio',
                    'provider_message_id' => $json['sid'] ?? null,
                    'segments' => isset($json['num_segments']) ? (int) $json['num_segments'] : null,
                    'test_only' => $testOnly,
                ];
            }

            $body = $res->json();

            return [
                'success' => false,
                'message' => $this->normalizeError(is_array($body) ? $body : (string) $res->body()),
                'test_only' => $testOnly,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $this->normalizeError($e), 'test_only' => $testOnly];
        }
    }

    public function normalizeError(\Throwable|\Exception|string|array $error): string
    {
        if (is_array($error)) {
            $code = $error['code'] ?? null;
            $message = $error['message'] ?? ($error['more_info'] ?? '');
            $message = is_string($message) ? $message : '';
            if (isset($code)) {
                return $this->stripSensitive("Twilio error {$code}: {$message}");
            }

            return $this->stripSensitive($message ?: 'Twilio error');
        }

        return $this->stripSensitive((string) ($error instanceof \Throwable ? $error->getMessage() : $error));
    }
}
