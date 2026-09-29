<?php

namespace Modules\Sms\Services\Sms\Adapters;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MetaWhatsAppAdapter — the only place that talks to the Meta Graph API.
 *
 * All Meta HTTP calls live here; services/controllers never build Graph URLs
 * themselves. The Graph base URL carries no version so `apiVersion()` stays a
 * single source of truth (a versioned constant here used to produce
 * `/v25.0/v25.0/...` URLs).
 */
class MetaWhatsAppAdapter
{
    public const GRAPH_URL = 'https://graph.facebook.com';

    private const API_VERSION = 'v25.0';

    private const LANGUAGE_MAP = [
        'en' => 'en_US',
        'en_us' => 'en_US',
        'ar' => 'ar',
        'de' => 'de',
        'fr' => 'fr',
        'es' => 'es',
        'it' => 'it',
        'pt' => 'pt_BR',
        'pt_br' => 'pt_BR',
        'ru' => 'ru',
        'tr' => 'tr',
        'hi' => 'hi',
        'ur' => 'ur',
    ];

    public function __construct(protected array $config) {}

    public static function requiredCredentials(): array
    {
        return [
            ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true, 'secret' => true],
            ['key' => 'phone_number_id', 'label' => 'Phone Number ID', 'type' => 'text', 'required' => true, 'secret' => false],
            ['key' => 'business_account_id', 'label' => 'WhatsApp Business Account ID', 'type' => 'text', 'required' => true, 'secret' => false],
            ['key' => 'api_version', 'label' => 'API Version', 'type' => 'text', 'required' => false, 'secret' => false],
        ];
    }

    public function capabilities(): array
    {
        return ['send_sms', 'template_messages', 'otp'];
    }

    /* --------------------------------------------------------------------- *
     | Connection
     * --------------------------------------------------------------------- */

    /**
     * Verify the access token and that the WhatsApp Business Account is
     * reachable. The token is sent as a Bearer header, never as a query
     * parameter (query tokens leak into proxy/SDK logs).
     *
     * @return array{success: bool, message: string, data?: array}
     */
    public function testConnection(): array
    {
        if (! $this->token()) {
            return ['success' => false, 'message' => 'Access Token is required'];
        }

        try {
            $me = $this->http()->get($this->url('/me'), ['fields' => 'id,name']);

            if (! $me->successful()) {
                return ['success' => false, 'message' => $this->safeHttpError($me)];
            }

            $businessAccountId = $this->config['business_account_id'] ?? null;
            $data = ['graph_user_id' => $me->json('id')];

            if ($businessAccountId) {
                $account = $this->http()->get($this->url('/'.$businessAccountId), ['fields' => 'id,name,verification_status']);

                if (! $account->successful()) {
                    return ['success' => false, 'message' => $this->safeHttpError($account)];
                }

                $data['business_account_id'] = $account->json('id');
                $data['business_account_name'] = $account->json('name');
            }

            if ($phoneNumberId = $this->config['phone_number_id'] ?? null) {
                $number = $this->testNumber((string) $phoneNumberId);

                $data['phone_number_id'] = $number['data']['phone_number_id'] ?? null;
                $data['display_phone_number'] = $number['data']['display_phone_number'] ?? null;

                if (! $number['success']) {
                    return [
                        'success' => false,
                        'message' => $number['message'],
                        'data' => $data,
                    ];
                }
            }

            return [
                'success' => true,
                'message' => 'Meta WhatsApp connection successful',
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $this->normalizeError($e)];
        }
    }

    /**
     * Verify a Meta phone number id: it must exist and be linked to the
     * business account.
     *
     * @return array{success: bool, message: string, data?: array}
     */
    public function testNumber(string $phoneNumberId): array
    {
        if (! $this->token()) {
            return ['success' => false, 'message' => 'Access Token is required'];
        }

        if ($phoneNumberId === '') {
            return ['success' => false, 'message' => 'Phone Number ID is required to test'];
        }

        try {
            $res = $this->http()->get($this->url('/'.$phoneNumberId), [
                'fields' => 'id,display_phone_number,verified_name,quality_score,code_verification_status',
            ]);

            if (! $res->successful()) {
                return ['success' => false, 'message' => $this->safeHttpError($res)];
            }

            $quality = $res->json('quality_score');
            if (is_array($quality)) {
                $quality = $quality['score'] ?? null;
            }

            return [
                'success' => true,
                'message' => 'Phone number is valid and linked to the business account',
                'data' => [
                    'phone_number_id' => $res->json('id'),
                    'display_phone_number' => $res->json('display_phone_number'),
                    'verified_name' => $res->json('verified_name'),
                    'quality' => $quality,
                    'code_verification_status' => $res->json('code_verification_status'),
                ],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $this->normalizeError($e)];
        }
    }

    /* --------------------------------------------------------------------- *
     | Templates
     * --------------------------------------------------------------------- */

    /**
     * Create or update a message template on Meta through
     * `upsert_message_templates`. The plain create endpoint rejects an
     * existing name+language combination with code 100 / subcode 2388024
     * ("Content in This Language Already Exists"), so submission always
     * goes through the upsert endpoint. A singular `language` is converted
     * into the `languages` array the upsert contract expects.
     *
     * @return array{success: bool, message: string, meta_template_id?: string, error?: array}
     */
    public function upsertTemplate(array $payload): array
    {
        if (! $this->token()) {
            return ['success' => false, 'message' => 'Access Token is required'];
        }

        $businessAccountId = $this->config['business_account_id'] ?? null;

        if (! $businessAccountId) {
            return ['success' => false, 'message' => 'WhatsApp Business Account ID is required'];
        }

        if (array_key_exists('language', $payload)) {
            $payload['languages'] = [(string) $payload['language']];
            unset($payload['language']);
        }

        try {
            $res = $this->http()->asJson()
                ->post($this->url('/'.$businessAccountId.'/upsert_message_templates'), $payload);

            $this->logMetaCall('upsert_template', (string) $businessAccountId, null, $res);

            if (! $res->successful()) {
                return [
                    'success' => false,
                    'message' => 'WhatsApp template submission failed: '.$this->safeHttpError($res),
                    'error' => $this->safeMetaError($res->json()),
                ];
            }

            // Upsert answers with a `data` array holding one entry per language;
            // a per-entry error means Meta refused that template.
            $data = array_values($res->json('data') ?? []);
            $entryError = is_array($data[0]['error'] ?? null) ? $data[0]['error'] : null;

            if ($entryError) {
                $safe = $this->safeMetaError(['error' => $entryError]);

                return [
                    'success' => false,
                    'message' => 'WhatsApp template submission failed: '.($safe['message'] ?: 'Meta rejected the template'),
                    'error' => $safe,
                ];
            }

            return [
                'success' => true,
                'message' => 'WhatsApp message template submitted to Meta',
                'meta_template_id' => (string) ($data[0]['id'] ?? $res->json('id') ?? ''),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'WhatsApp template submission failed: '.$this->normalizeError($e),
                'error' => ['code' => null, 'message' => $e->getMessage(), 'details' => null],
            ];
        }
    }

    /**
     * List every message template of the business account.
     *
     * @return array{success: bool, message: string, templates?: array<int, array> , error?: array}
     */
    public function fetchTemplates(): array
    {
        if (! $this->token()) {
            return ['success' => false, 'message' => 'Access Token is required'];
        }

        $businessAccountId = $this->config['business_account_id'] ?? null;

        if (! $businessAccountId) {
            return ['success' => false, 'message' => 'WhatsApp Business Account ID is required'];
        }

        try {
            $res = $this->http()->get($this->url('/'.$businessAccountId.'/message_templates'), [
                'fields' => 'id,name,status,language,category,components,rejected_reason',
                'limit' => 100,
            ]);

            $this->logMetaCall('fetch_templates', (string) $businessAccountId, null, $res);

            if (! $res->successful()) {
                return [
                    'success' => false,
                    'message' => 'WhatsApp template sync failed: '.$this->safeHttpError($res),
                    'error' => $this->safeMetaError($res->json()),
                ];
            }

            return [
                'success' => true,
                'message' => 'WhatsApp message templates fetched from Meta',
                'templates' => array_values($res->json('data') ?? []),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'WhatsApp template sync failed: '.$this->normalizeError($e),
                'error' => ['code' => null, 'message' => $e->getMessage(), 'details' => null],
            ];
        }
    }

    /**
     * Refresh a single template by its Meta template id.
     *
     * @return array{success: bool, message: string, template?: array, error?: array}
     */
    public function fetchTemplate(string $metaTemplateId): array
    {
        if (! $this->token()) {
            return ['success' => false, 'message' => 'Access Token is required'];
        }

        if ($metaTemplateId === '') {
            return ['success' => false, 'message' => 'Meta template id is required'];
        }

        try {
            $res = $this->http()->get($this->url('/'.$metaTemplateId), [
                'fields' => 'id,name,status,language,category,components,rejected_reason',
            ]);

            $this->logMetaCall('fetch_template', null, $metaTemplateId, $res);

            if (! $res->successful()) {
                return [
                    'success' => false,
                    'message' => 'WhatsApp template status sync failed: '.$this->safeHttpError($res),
                    'error' => $this->safeMetaError($res->json()),
                ];
            }

            return ['success' => true, 'message' => 'WhatsApp template status fetched', 'template' => $res->json()];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'WhatsApp template status sync failed: '.$this->normalizeError($e),
                'error' => ['code' => null, 'message' => $e->getMessage(), 'details' => null],
            ];
        }
    }

    /**
     * Look a template up by name + language (used before it has a Meta id).
     *
     * @return array{success: bool, message: string, meta_status: string, template?: array}
     */
    public function validateTemplate(string $templateName, string $languageCode): array
    {
        $result = $this->fetchTemplates();

        if (! $result['success']) {
            return ['success' => false, 'meta_status' => 'unknown', 'message' => $result['message']];
        }

        $metaLanguage = $this->mapLanguage($languageCode);

        foreach ($result['templates'] as $template) {
            $sameName = strcasecmp((string) ($template['name'] ?? ''), $templateName) === 0;
            $sameLanguage = strcasecmp((string) ($template['language'] ?? ''), $metaLanguage) === 0;

            if ($sameName && $sameLanguage) {
                return [
                    'success' => true,
                    'meta_status' => $this->mapMetaStatus((string) ($template['status'] ?? 'unknown')),
                    'template' => $template,
                    'message' => 'Template found on Meta with status: '.($template['status'] ?? 'unknown'),
                ];
            }
        }

        return ['success' => true, 'meta_status' => 'unknown', 'message' => 'Template not found on Meta'];
    }

    /* --------------------------------------------------------------------- *
     | Sending
     * --------------------------------------------------------------------- */

    /**
     * Send an OTP through an approved AUTHENTICATION template.
     *
     * Meta expects a SINGLE body component holding every positional
     * parameter, not one component per value.
     *
     * @param  array<int, string>  $templateParams  Positional values in {{n}} order.
     * @return array{success: bool, message: string, provider_message_id?: string|null}
     */
    public function sendOtp(string $to, string $templateName, string $languageCode, array $templateParams): array
    {
        $phoneNumberId = $this->config['phone_number_id'] ?? null;

        if (! $this->token() || ! $phoneNumberId) {
            return ['success' => false, 'message' => 'Access Token and Phone Number ID are required'];
        }

        $template = [
            'name' => $templateName,
            'language' => ['code' => $this->mapLanguage($languageCode)],
        ];

        if ($templateParams !== []) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => array_values(array_map(
                    fn ($value) => ['type' => 'text', 'text' => (string) $value],
                    $templateParams,
                )),
            ]];
        }

        try {
            $res = $this->http()->asJson()->post($this->url('/'.$phoneNumberId.'/messages'), [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'template',
                'template' => $template,
            ]);

            $this->logMetaCall('send_template', null, null, $res);

            if ($res->successful()) {
                return [
                    'success' => true,
                    'message' => 'WhatsApp OTP sent',
                    'provider_message_id' => $res->json('messages.0.id'),
                ];
            }

            return ['success' => false, 'message' => $this->safeHttpError($res)];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $this->normalizeError($e)];
        }
    }

    /* --------------------------------------------------------------------- *
     | Internals
     * --------------------------------------------------------------------- */

    protected function token(): ?string
    {
        $token = $this->config['access_token'] ?? null;

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    /**
     * HTTP client with sane defaults (no exception on 4xx/5xx).
     */
    protected function http()
    {
        return Http::timeout(15)->connectTimeout(10)->withToken((string) $this->token());
    }

    /**
     * Build a versioned Graph URL from an unversioned path.
     */
    protected function url(string $path): string
    {
        return self::GRAPH_URL.'/'.$this->apiVersion().'/'.ltrim($path, '/');
    }

    protected function apiVersion(): string
    {
        $configured = $this->config['api_version'] ?? null;

        if (is_string($configured) && preg_match('/^v\d+\.\d+$/', trim($configured))) {
            return trim($configured);
        }

        return self::API_VERSION;
    }

    /**
     * Meta rejects bare two-letter European codes ("en" → HTTP 400), so every
     * short code maps onto a supported locale.
     */
    public function mapLanguage(string $code): string
    {
        $normalized = strtolower(trim($code));

        return self::LANGUAGE_MAP[$normalized] ?? $code;
    }

    protected function mapMetaStatus(string $status): string
    {
        return match (strtolower($status)) {
            'approved' => 'approved',
            'pending' => 'pending',
            'in_review' => 'pending',
            'rejected' => 'rejected',
            'paused' => 'paused',
            'disabled' => 'disabled',
            default => 'unknown',
        };
    }

    /**
     * A one-line, credential-free error for an HTTP failure.
     */
    public function safeHttpError(Response $res): string
    {
        $error = $res->json('error');
        $message = is_array($error) ? ($error['message'] ?? null) : null;

        if (! $message) {
            return 'HTTP '.$res->status();
        }

        $details = is_array($error) ? ($error['error_data']['details'] ?? null) : null;
        $line = 'HTTP '.$res->status().': '.(string) $message;

        return $details ? $line.' — '.(string) $details : $line;
    }

    /**
     * Normalize a Meta failure into {code, message, details} so callers can
     * persist a safe error (e.g. in `last_sync_error`).
     *
     * @return array{code: mixed, message: string, details: string|null}
     */
    public function safeMetaError(?array $json): array
    {
        $error = $json['error'] ?? null;

        if (! is_array($error)) {
            return ['code' => null, 'message' => 'Unknown Meta API error', 'details' => null];
        }

        $message = $error['message'] ?? null;

        if (! $message) {
            return ['code' => null, 'message' => 'Unknown Meta API error', 'details' => null];
        }

        $code = $error['code'] ?? null;
        $details = $error['error_data']['details'] ?? null;

        return [
            'code' => is_scalar($code) ? $code : null,
            'message' => BaseSmsAdapter::stripSensitive((string) $message),
            'details' => is_string($details) && $details !== '' ? BaseSmsAdapter::stripSensitive($details) : null,
        ];
    }

    /**
     * Flatten a normalized Meta error into a single storable line.
     */
    public function normalizeSyncError(?array $error, ?string $message = null): string
    {
        if (is_array($error) && filled($error['message'] ?? null)) {
            $line = trim(sprintf('%s: %s', (string) ($error['code'] ?? ''), (string) $error['message']), " :\n\r\t");

            return filled($error['details'] ?? null) ? $line.' — '.(string) $error['details'] : $line;
        }

        return (string) ($message ?: 'Unknown Meta API error');
    }

    /**
     * Credential-free debug line for every Meta interaction.
     */
    protected function logMetaCall(string $type, ?string $businessAccountId, ?string $metaTemplateId, Response $res): void
    {
        $error = $this->safeMetaError($res->json());

        Log::channel(config('sms.log_channel', 'stack'))->debug('whatsapp.meta.call', [
            'request_type' => $type,
            'business_account_id' => $businessAccountId,
            'meta_template_id' => $metaTemplateId,
            'response_status' => $res->status(),
            'error_code' => $error['code'],
            'error_message' => $res->successful() ? null : $error['message'],
        ]);
    }

    protected function normalizeError(\Throwable $e): string
    {
        return BaseSmsAdapter::stripSensitive($e->getMessage());
    }
}
