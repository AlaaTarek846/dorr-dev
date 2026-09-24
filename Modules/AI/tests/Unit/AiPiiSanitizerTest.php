<?php

namespace Modules\AI\Tests\Unit;

use Modules\AI\Services\AiPiiSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * v2.0 requirements doc §17.3/§20.1: unit coverage for the pattern-based
 * PII/secret redaction used both for outgoing-provider sanitization
 * (AiGateway::applyDataRules) and audit-log minimization
 * (AiChatService::minimizedForLog). Pure functions, no DB/framework
 * needed - real PHPUnit\Framework\TestCase, not Laravel's.
 */
class AiPiiSanitizerTest extends TestCase
{
    protected AiPiiSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new AiPiiSanitizer;
    }

    public function test_redacts_email_addresses(): void
    {
        $result = $this->sanitizer->redactPii('Contact me at ali689.kh@gmail.com please.');

        $this->assertSame('Contact me at [email] please.', $result);
    }

    public function test_redacts_phone_numbers(): void
    {
        $result = $this->sanitizer->redactPii('My number is +20 100 123 4567 call me.');

        $this->assertStringContainsString('[phone]', $result);
        $this->assertStringNotContainsString('100 123 4567', $result);
    }

    public function test_redacts_long_digit_runs_as_id_numbers(): void
    {
        $result = $this->sanitizer->redactPii('My national ID is 29901011234567 thanks.');

        $this->assertStringContainsString('[id_number]', $result);
    }

    public function test_does_not_touch_short_numbers(): void
    {
        $result = $this->sanitizer->redactPii('I need 5 items and it costs 120.');

        $this->assertSame('I need 5 items and it costs 120.', $result);
    }

    public function test_redacts_openai_style_api_key_prefix(): void
    {
        $result = $this->sanitizer->redactSecrets('sk-proj-abcdefghijklmnopqrstuvwxyz123456 is my key');

        $this->assertStringContainsString('[secret]', $result);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz', $result);
    }

    public function test_redacts_bearer_tokens(): void
    {
        $result = $this->sanitizer->redactSecrets('Authorization: Bearer abcdef1234567890.xyz1234567890');

        $this->assertStringContainsString('Bearer [secret]', $result);
    }

    public function test_redacts_key_value_secret_patterns(): void
    {
        $result = $this->sanitizer->redactSecrets('api_key: sk_live_1234567890abcdef');

        $this->assertStringContainsString('[secret]', $result);
    }

    public function test_redacts_long_opaque_tokens(): void
    {
        $result = $this->sanitizer->redactSecrets('token value: aB3dE6fG9hJ2kL5mN8pQ1rS4tU7vW0xY');

        $this->assertStringContainsString('[secret]', $result);
    }

    public function test_redact_both_applies_pii_then_secrets(): void
    {
        $result = $this->sanitizer->redactBoth('Email me at test@example.com with key sk-abcdefghijklmnopqrstuvwx12345');

        $this->assertStringContainsString('[email]', $result);
        $this->assertStringContainsString('[secret]', $result);
    }

    public function test_leaves_ordinary_text_untouched(): void
    {
        $text = 'The delivery will arrive tomorrow at 3pm, thank you!';

        $this->assertSame($text, $this->sanitizer->redactBoth($text));
    }

    /**
     * Regression test: redactBoth() must run secret redaction BEFORE PII
     * redaction. Doing it the other way round lets the PII phone-number
     * pattern (digits + separators) partially consume a Bearer token
     * first, so the secret pattern never recognizes the token whole and
     * it leaks out as a mangled "Bearer abcdef[phone].xyz[phone]"
     * instead of being fully masked.
     */
    public function test_redact_both_does_not_let_pii_pattern_corrupt_a_bearer_token(): void
    {
        $result = $this->sanitizer->redactBoth('Authorization: Bearer abcdef1234567890.xyz1234567890');

        $this->assertSame('Authorization: Bearer [secret]', $result);
    }
}
