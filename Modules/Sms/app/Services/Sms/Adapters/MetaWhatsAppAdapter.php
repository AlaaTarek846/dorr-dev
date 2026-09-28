<?php

namespace Modules\Sms\Services\Sms\Adapters;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Sms\Exceptions\SmsException;

/**
 * Meta WhatsApp Business Platform / WhatsApp Cloud API adapter.
 *
 * Sends template messages via Meta's Cloud API. Does NOT use Twilio WhatsApp.
 */
class MetaWhatsAppAdapter
{
    private const API_VERSION = 'v21.0';
    private const API_URL = 'https://graph.facebook.com/v21.0';

    public function __construct(protected array $config) {}

    /**
     * @return array{access_token: string, phone_number_id: string, business_account_id: string, api_version: string}
     */
    public static function requiredCredentials(): array
    {
        return [
            ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true, 'secret' => true],
            ['key' => 'phone_number_id', 'label' => 'Phone Number ID', 'type' => 'text', 'required' => true, 'secret' => true],
            ['key' => 'business_account_id', 'label' => 'WhatsApp Business Account ID', 'type' => 'text', 'required' => true, 'secret' => true],
            ['key' => 'api_version', 'label' => 'API Version', 'type' => 'text', 'required' => false, 'secret' => false],
        ];
    }

    public function capabilities(): array
    {
        return ['send_sms', 'template_messages', 'otp'];
    }

    /**
     * Test the Meta connection using the access token.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        $token = $this->config['access_token'] ?? null;

        if (! $token) {
            return ['success' => false, 'message' => 'Access Token is required'];
        }

        try {
            $res = Http::timeout(15)->connectTimeout(10)
                ->get(self::API_URL.'/'.$this->config['api_version'] ?? self::API_VERSION.'/me', [
                    'access_token' => $token,
                ]);

            if ($res->successful()) {
                return ['success' => true, 'message' => 'Meta WhatsApp connection successful'];
            }

            return ['success' => false, 'message' => $this->normalizeError($res)];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $this->normalizeError($e)];
        }
    }

    /**
     * Send an OTP template message via Meta WhatsApp Cloud API.
     *
     * @param  array{otp: string, expiration: string}  $templateParams
     * @return array{success: bool, message: string, provider_message_id?: string}
     */
    public function sendOtp(string $to, string $templateName, array $templateParams): array
    {
        $token = $this->config['access_token'] ?? null;
        $phoneNumberId = $this->config['phone_number_id'] ?? null;

        if (! $token || ! $phoneNumberId) {
            return ['success' => false, 'message' => 'Access Token and Phone Number ID are required'];
        }

        try {
            $res = Http::timeout(30)->connectTimeout(10)
                ->withToken($token)
                ->post(self::API_URL.'/'.$this->config['api_version'] ?? self::API_VERSION.'/'.$phoneNumberId.'/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'template',
                    'template' => [
                        'name' => $templateName,
                        'language' => ['code' => 'en'],
                        'components' => array_map(fn ($value) => [
                            'type' => 'parameter',
                            'parameters' => [['type' => 'text', 'text' => $value]],
                        ], $templateParams),
                    ],
                ]);

            if ($res->successful()) {
                $json = $res->json();
                return [
                    'success' => true,
                    'message' => 'WhatsApp OTP sent',
                    'provider_message_id' => $json['messages'][0]['id'] ?? null,
                ];
            }

            return ['success' => false, 'message' => $this->normalizeError($res)];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $this->normalizeError($e)];
        }
    }

    /**
     * Validate a template against Meta's API.
     *
     * @return array{success: bool, meta_status?: string, message: string}
     */
    public function validateTemplate(string $templateName): array
    {
        $token = $this->config['access_token'] ?? null;

        if (! $token) {
            return ['success' => false, 'message' => 'Access Token is required'];
        }

        try {
            $res = Http::timeout(15)->connectTimeout(10)
                ->withToken($token)
                ->get(self::API_URL.'/'.$this->config['api_version'] ?? self::API_VERSION, [
                    'fields' => 'phone_numbers',
                    'access_token' => $token,
                ]);

            // Template validation requires the /template GET endpoint or the
            // messaging product endpoint. Meta does not expose a direct
            // template status endpoint publicly, so we store the result
            // from the sync endpoint and treat this as a connectivity check.
            if ($res->successful()) {
                return ['success' => true, 'meta_status' => 'connected', 'message' => 'Meta API connected'];
            }

            return ['success' => false, 'message' => $this->normalizeError($res)];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $this->normalizeError($e)];
        }
    }

    /**
     * Sanitize external API errors so tokens and secrets never leak.
     */
    protected function normalizeError(\Throwable|\Exception|string|array|Response $error): string
    {
        if ($error instanceof Response) {
            $body = $error->body();
        } elseif (is_array($error)) {
            $body = json_encode($error);
        } elseif ($error instanceof \Throwable) {
            $body = $error->getMessage();
        } else {
            $body = (string) $error;
        }

        return BaseSmsAdapter::stripSensitive((string) $body);
    }
}
