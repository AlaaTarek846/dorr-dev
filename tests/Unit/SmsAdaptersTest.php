<?php

namespace Tests\Unit;

use App\Models\Country;
use Illuminate\Support\Facades\Http;
use Modules\Sms\Contracts\Sms\SmsProviderInterface;
use Modules\Sms\Exceptions\SmsException;
use Modules\Sms\Services\Sms\Adapters\FourJawalySmsAdapter;
use Modules\Sms\Services\Sms\Adapters\SmsMisrSmsAdapter;
use Modules\Sms\Services\Sms\Adapters\TwilioSmsAdapter;
use Modules\Sms\Services\Sms\PhoneNumberNormalizer;
use Modules\Sms\Services\Sms\SmsAdapterRegistry;
use Modules\Sms\Services\Sms\SmsMessageHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmsAdaptersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders(['Accept-Language' => 'en']);
    }

    /**
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function providerKeys(): array
    {
        return [
            'twilio' => ['twilio', TwilioSmsAdapter::class],
            'sms_misr' => ['sms_misr', SmsMisrSmsAdapter::class],
            'four_jawaly' => ['four_jawaly', FourJawalySmsAdapter::class],
        ];
    }

    #[DataProvider('providerKeys')]
    public function test_registry_resolves_every_adapter(string $key, string $adapterClass): void
    {
        $service = SmsAdapterRegistry::instance();

        $this->assertContains($key, $service->keys(), "Provider '{$key}' must be registered");

        $adapter = $service->adapter($key);
        $this->assertInstanceOf($adapterClass, $adapter);
        $this->assertInstanceOf(SmsProviderInterface::class, $adapter);
        $this->assertSame($key, $adapter->key());
    }

    #[DataProvider('providerKeys')]
    public function test_every_adapter_declares_schema_and_capabilities(string $key): void
    {
        $adapter = SmsAdapterRegistry::instance()->adapter($key);

        $schema = $adapter->configurationSchema();
        $this->assertNotEmpty($schema, "Adapter '{$key}' must declare a configuration schema");
        $this->assertTrue(
            collect($schema)->contains(fn ($field) => ($field['required'] ?? false) && ($field['secret'] ?? false)),
            "Adapter '{$key}' must protect its credentials as secrets",
        );

        $capabilities = $adapter->capabilities();
        $this->assertContains('send_sms', $capabilities, "Adapter '{$key}' must support sending");

        foreach ($capabilities as $capability) {
            $this->assertContains($capability, [
                'send_sms', 'send_bulk', 'balance', 'test_mode',
                'delivery_reports', 'sender_ids', 'otp', 'two_way', 'sender_approval',
            ], "Unknown capability '{$capability}' on '{$key}'");
        }
    }

    #[DataProvider('providerKeys')]
    public function test_every_adapter_rejects_empty_credentials_without_network(string $key): void
    {
        $adapter = SmsAdapterRegistry::instance()->adapter($key);

        $result = $adapter->testConnection([]);

        $this->assertFalse($result['success'], "Adapter '{$key}' must fail on empty credentials");
        $this->assertNotEmpty($result['message']);
    }

    public function test_validate_configuration_detects_missing_required_fields(): void
    {
        $service = SmsAdapterRegistry::instance();

        [$valid, $missing] = $service->validateConfiguration('twilio', [
            'account_sid' => 'AC123',
            'from' => '+15551234567',
            // auth_token missing
        ]);

        $this->assertFalse($valid);
        $this->assertContains('auth_token', $missing);

        [$valid, $missing] = $service->validateConfiguration('twilio', [
            'account_sid' => 'AC123',
            'auth_token' => 'tok',
            'from' => '+15551234567',
        ]);

        $this->assertTrue($valid);
        $this->assertEmpty($missing);

        // On update, secrets may be blank (the stored value is kept).
        [$valid, $missing] = $service->validateConfiguration('twilio', [
            'account_sid' => 'AC123',
            'from' => '+15551234567',
        ], true);

        $this->assertTrue($valid, 'Blank secrets must be allowed on update');
    }

    public function test_prepare_configuration_keeps_blank_secrets(): void
    {
        $service = SmsAdapterRegistry::instance();

        $existing = [
            'account_sid' => 'AC-keep-me',
            'auth_token' => 'old-secret-kept',
            'from' => '+15551234567',
        ];

        $prepared = $service->prepareConfiguration('twilio', [
            'account_sid' => 'AC-keep-me',
            'auth_token' => '', // blank means keep existing
            'from' => '+15550000000',
        ], $existing);

        $this->assertSame('old-secret-kept', $prepared['auth_token'], 'Blank secret must keep the stored value');
        $this->assertSame('+15550000000', $prepared['from'], 'Non-secret value must update');
    }

    public function test_prepare_configuration_returns_plaintext_not_encrypted(): void
    {
        $service = SmsAdapterRegistry::instance();

        $prepared = $service->prepareConfiguration('twilio', [
            'account_sid' => 'AC-plain',
            'auth_token' => 'plain-token',
            'from' => '+15550000000',
        ]);

        // Encryption is the model's job via its `encrypted:array` cast. If the
        // registry encrypted as well the value would arrive double-encrypted and
        // every read would return ciphertext instead of an array.
        $this->assertSame('AC-plain', $prepared['account_sid']);
        $this->assertSame('plain-token', $prepared['auth_token']);
    }

    public function test_normalize_error_strips_secrets(): void
    {
        $adapter = new TwilioSmsAdapter;

        $leaked = $adapter->normalizeError([
            'code' => 20401,
            'message' => 'Invalid api_key sk-x9c8v7b6n5m4a3z2 supplied',
        ]);

        $this->assertStringNotContainsString('sk-x9c8v7b6n5m4a3z2', $leaked, 'Secret values must be stripped from normalized errors');
        $this->assertStringContainsString('20401', $leaked);

        $leaked = $adapter->normalizeError('Auth failed: password hunter2secret');
        $this->assertStringNotContainsString('hunter2secret', $leaked);
    }

    public function test_sms_misr_maps_provider_codes(): void
    {
        $adapter = new SmsMisrSmsAdapter;

        $this->assertStringContainsString('Invalid username or password', $this->mapProtected($adapter, 1903));
        $this->assertStringContainsString('Invalid sender', $this->mapProtected($adapter, 1904));
        $this->assertStringContainsString('Insufficient balance', $this->mapProtected($adapter, 1906));
        $this->assertStringContainsString('temporarily updating', $this->mapProtected($adapter, 1907));

        // An unknown code falls back to the generic message, not a raw key.
        $this->assertStringContainsString('9999', $this->mapProtected($adapter, 9999));
    }

    /**
     * mapMisrCode() is protected on the adapter.
     */
    protected function mapProtected(SmsMisrSmsAdapter $adapter, int $code): string
    {
        return (new \ReflectionMethod($adapter, 'mapMisrCode'))->invoke($adapter, $code);
    }

    public function test_message_helper_gsm7_detection(): void
    {
        $helper = new SmsMessageHelper;

        $this->assertTrue($helper->isGsm7('Hello world!'));
        $this->assertFalse($helper->isGsm7('مرحبا بالعالم'));
        $this->assertTrue($helper->isUnicode('مرحبا بالعالم'));
    }

    public function test_message_helper_segment_counting(): void
    {
        $helper = new SmsMessageHelper;

        // GSM-7: 160 -> 1 segment, 161 -> 2 (153 chars per part).
        $this->assertSame(1, $helper->estimateSegments(str_repeat('a', 160)));
        $this->assertSame(2, $helper->estimateSegments(str_repeat('a', 161)));
        $this->assertSame(2, $helper->estimateSegments(str_repeat('a', 306)));
        $this->assertSame(3, $helper->estimateSegments(str_repeat('a', 307)));

        // Unicode / Arabic: 70 -> 1 segment, 71 -> 2 (67 chars per part).
        $this->assertSame(1, $helper->estimateSegments(implode('', array_fill(0, 70, 'ا'))));
        $this->assertSame(2, $helper->estimateSegments(implode('', array_fill(0, 71, 'ا'))));

        // Provider-specific limits override the defaults.
        $this->assertSame(1, $helper->estimateSegments(str_repeat('a', 200), ['single' => 200, 'multipart' => 180]));
        $this->assertSame(2, $helper->estimateSegments(str_repeat('a', 201), ['single' => 200, 'multipart' => 180]));
    }

    public function test_message_helper_charset_specs(): void
    {
        $helper = new SmsMessageHelper;

        $gsm = $helper->charsetSpecs('Hello');
        $this->assertSame('GSM-7', $gsm['charset']);
        $this->assertSame(160, $gsm['per_segment']);

        $unicode = $helper->charsetSpecs('مرحبا');
        $this->assertSame('UCS-2', $unicode['charset']);
        $this->assertSame(70, $unicode['per_segment']);
    }

    public function test_capability_lookup_helpers(): void
    {
        $service = SmsAdapterRegistry::instance();

        $this->assertTrue($service->supports('twilio', 'balance'));
        $this->assertTrue($service->supports('sms_misr', 'sender_approval'));
        $this->assertFalse($service->supports('sms_misr', 'balance'), 'SMS Misr has no balance API');
        $this->assertFalse($service->supports('sms_misr', 'delivery_reports'));
        $this->assertFalse($service->supports('unknown_provider', 'send_sms'));
        $this->assertSame('—', $service->label(null));
        $this->assertSame('Twilio', $service->label('twilio'));
    }

    public function test_four_jawaly_sends_with_basic_auth_and_bare_international_number(): void
    {
        Http::fake([
            'api-sms.4jawaly.com/api/v1/account/area/sms/send' => Http::response([
                'job_id' => 'job-123',
                'messages' => [['err_text' => null]],
            ], 200),
        ]);

        $result = (new FourJawalySmsAdapter)->send([
            'api_key' => 'key-123',
            'api_secret' => 'secret-456',
            'sender' => 'Dorr',
        ], [
            'to' => '+966501234567',
            'message' => 'Hello',
            'from' => null,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('job-123', $result['provider_message_id']);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://api-sms.4jawaly.com/api/v1/account/area/sms/send'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('key-123:secret-456'))
                && $body['messages'][0]['numbers'] === ['966501234567']
                && $body['messages'][0]['sender'] === 'Dorr'
                && $body['messages'][0]['text'] === 'Hello';
        });
    }

    public function test_four_jawaly_send_reports_per_message_errors(): void
    {
        Http::fake([
            'api-sms.4jawaly.com/api/v1/account/area/sms/send' => Http::response([
                'job_id' => 'job-999',
                'messages' => [['err_text' => 'Invalid sender name']],
            ], 200),
        ]);

        $result = (new FourJawalySmsAdapter)->send([
            'api_key' => 'k',
            'api_secret' => 's',
            'sender' => 'Dorr',
        ], [
            'to' => '966501234567',
            'message' => 'Hello',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid sender name', $result['message']);
    }

    public function test_four_jawaly_balance_sums_current_points(): void
    {
        Http::fake([
            'api-sms.4jawaly.com/api/v1/account/area/me/packages*' => Http::response([
                'collection' => [
                    ['id' => 1, 'current_points' => 120],
                    ['id' => 2, 'current_points' => 30.5],
                ],
            ], 200),
        ]);

        $result = (new FourJawalySmsAdapter)->getBalance(['api_key' => 'k', 'api_secret' => 's']);

        $this->assertTrue($result['success']);
        $this->assertSame(150.5, $result['balance']);
        $this->assertSame('points', $result['currency']);
    }

    public function test_four_jawaly_reads_senders_from_both_shapes(): void
    {
        Http::fake([
            'api-sms.4jawaly.com/api/v1/account/area/senders*' => Http::response([
                'items' => [
                    ['sender_name' => 'Dorr', 'is_default' => true],
                    ['sender_name' => 'Support'],
                    ['sender_name' => 'Dorr'],
                ],
            ], 200),
        ]);

        $result = (new FourJawalySmsAdapter)->getSenderIds(['api_key' => 'k', 'api_secret' => 's']);

        $this->assertTrue($result['success']);
        $this->assertSame(['Dorr', 'Support'], $result['senders']);
    }

    /* ------------------------------------------------------------------ *
     | Country-driven E.164 normalization
     * ------------------------------------------------------------------ */
    public function test_normalizer_converts_national_numbers_using_the_selected_country(): void
    {
        $normalizer = app(PhoneNumberNormalizer::class);
        $country = $this->country('EG');

        // phone_starts_with = 1, phone_length = 10 (excludes the trunk zero).
        $this->assertSame('+201012345678', $normalizer->normalize('01012345678', $country));
        $this->assertSame('+201012345678', $normalizer->normalize('1012345678', $country));
        $this->assertSame('+201012345678', $normalizer->normalize('010 123 45678', $country));
        $this->assertSame('+201012345678', $normalizer->normalize('010-123-45678', $country));
    }

    public function test_normalizer_keeps_international_numbers_and_drops_a_redundant_zero(): void
    {
        $normalizer = app(PhoneNumberNormalizer::class);
        $country = $this->country('EG');

        $this->assertSame('+201012345678', $normalizer->normalize('+201012345678', $country));
        $this->assertSame('+201012345678', $normalizer->normalize('+20 010 1234 5678', $country));
    }

    public function test_normalizer_uses_each_countries_own_rule(): void
    {
        $normalizer = app(PhoneNumberNormalizer::class);

        // SA: phone_starts_with = 5, phone_length = 9.
        $saudi = $this->country('SA');
        $this->assertSame('+966501234567', $normalizer->normalize('0501234567', $saudi));
        $this->assertSame('+966501234567', $normalizer->normalize('501234567', $saudi));

        // US: no national trunk zero, phone_length = 10.
        $us = $this->country('US');
        $this->assertSame('+12015550123', $normalizer->normalize('2015550123', $us));
    }

    public function test_normalizer_rejects_a_number_from_a_different_country(): void
    {
        $normalizer = app(PhoneNumberNormalizer::class);

        $this->expectException(SmsException::class);
        $this->expectExceptionMessage('does not belong to the selected country');

        // A Saudi number submitted while Egypt is selected.
        $normalizer->normalize('+966501234567', $this->country('EG'));
    }

    public function test_normalizer_rejects_a_wrong_mobile_prefix(): void
    {
        $normalizer = app(PhoneNumberNormalizer::class);

        $this->expectException(SmsException::class);
        $this->expectExceptionMessage('is not valid for the selected country');

        // Egyptan mobiles start with 1, never 2.
        $normalizer->normalize('02012345678', $this->country('EG'));
    }

    public function test_normalizer_rejects_a_wrong_length(): void
    {
        $normalizer = app(PhoneNumberNormalizer::class);

        $this->expectException(SmsException::class);
        $this->expectExceptionMessage('is not valid for the selected country');

        $normalizer->normalize('123', $this->country('EG'));
    }

    public function test_normalizer_rejects_an_empty_number(): void
    {
        $normalizer = app(PhoneNumberNormalizer::class);

        $this->expectException(SmsException::class);

        $normalizer->normalize('   ', $this->country('EG'));
    }

    /**
     * An in-memory Country carrying the same phone rules as
     * database/seeders/data/country-phone-rules.json. No DB row is needed —
     * the normalizer only reads the model's attributes.
     */
    protected function country(string $code): Country
    {
        $rules = [
            'EG' => ['dial_code' => '+20', 'phone_starts_with' => '1', 'phone_length' => 10],
            'SA' => ['dial_code' => '+966', 'phone_starts_with' => '5', 'phone_length' => 9],
            'US' => ['dial_code' => '+1', 'phone_starts_with' => '2', 'phone_length' => 10],
        ][$code] ?? null;

        $this->assertNotNull($rules, "No phone rule defined for '{$code}' in the test fixture");

        return new Country(array_merge($rules, ['code' => $code]));
    }
}
