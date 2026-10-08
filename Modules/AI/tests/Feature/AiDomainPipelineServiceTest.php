<?php

namespace Modules\AI\Tests\Feature;

use Modules\AI\Models\AiCodeExecution;
use Modules\AI\Models\AiDomainPolicy;
use Modules\AI\Services\AiDomainClassifier;
use Modules\AI\Services\AiDomainPipelineService;
use Modules\AI\Services\AiSandboxRunner;
use Tests\TestCase;

/**
 * v2.0 requirements doc §7-14/§20.1-20.2: covers the domain-pipeline
 * behaviors that gate or shape a reply BEFORE/AFTER the AI is called -
 * the health emergency triage gate (§8.1), the legal jurisdiction nudge
 * (§7.1), and code-block extraction for the sandbox (§10.3). Uses
 * Laravel's TestCase (not plain PHPUnit) because these methods call the
 * translator (__()) and config() helpers, which need a booted app - but
 * no database access is needed, so RefreshDatabase is deliberately not
 * used here for speed.
 */
class AiDomainPipelineServiceTest extends TestCase
{
    protected AiDomainPipelineService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AiDomainPipelineService(
            new AiDomainClassifier,
            new AiSandboxRunner,
        );
    }

    public function test_triage_reply_is_null_when_policy_does_not_require_triage(): void
    {
        $policy = new AiDomainPolicy(['requires_triage' => false]);

        $this->assertNull($this->service->triageReply($policy, 'I feel a bit tired today.'));
    }

    public function test_triage_reply_is_null_when_policy_is_absent(): void
    {
        $this->assertNull($this->service->triageReply(null, 'انا حاسس اني هموت من الوجع'));
    }

    public function test_triage_reply_fires_on_arabic_emergency_keyword(): void
    {
        $policy = new AiDomainPolicy(['requires_triage' => true]);

        $reply = $this->service->triageReply($policy, 'حاسس بألم شديد في الصدر ومش قادر اتنفس');

        $this->assertNotNull($reply);
        $this->assertSame(__('ai.health_triage_emergency_reply'), $reply);
    }

    public function test_triage_reply_fires_on_english_emergency_keyword(): void
    {
        $policy = new AiDomainPolicy(['requires_triage' => true]);

        $reply = $this->service->triageReply($policy, 'I think I am having a heart attack right now');

        $this->assertNotNull($reply);
    }

    public function test_triage_reply_is_null_for_ordinary_health_question(): void
    {
        $policy = new AiDomainPolicy(['requires_triage' => true]);

        $reply = $this->service->triageReply($policy, 'ايه أفضل علاج للصداع البسيط؟');

        $this->assertNull($reply);
    }

    public function test_system_guidance_asks_for_jurisdiction_when_none_mentioned(): void
    {
        $policy = new AiDomainPolicy(['requires_jurisdiction' => true]);

        $guidance = $this->service->systemGuidance($policy, 'ما هي حقوقي في حالة الفصل من العمل؟');

        $this->assertNotNull($guidance);
        $this->assertStringContainsString('jurisdiction', $guidance);
    }

    public function test_system_guidance_suppresses_jurisdiction_note_when_a_country_is_named(): void
    {
        // When a country IS named, mentionsJurisdiction() suppresses the
        // jurisdiction line; with nothing else configured on this policy
        // (no system_prompt_addition/disclaimer/other flags), the whole
        // guidance string collapses to null - there is nothing left to say.
        $policy = new AiDomainPolicy(['requires_jurisdiction' => true]);

        $guidance = $this->service->systemGuidance($policy, 'ما هي حقوقي في حالة الفصل من العمل في مصر؟');

        $this->assertNull($guidance);
    }

    public function test_system_guidance_is_null_for_a_policy_with_nothing_to_add(): void
    {
        $policy = new AiDomainPolicy([
            'requires_jurisdiction' => false,
            'requires_triage' => false,
            'domain_key' => AiDomainPolicy::DOMAIN_EDUCATION,
            'system_prompt_addition' => null,
            'disclaimer_text' => null,
        ]);

        $this->assertNull($this->service->systemGuidance($policy, 'اشرحلي نظرية فيثاغورس'));
    }

    public function test_extract_code_block_returns_null_when_no_fenced_block_present(): void
    {
        $this->assertNull($this->service->extractCodeBlock('Just a plain text reply with no code.'));
    }

    public function test_extract_code_block_finds_a_supported_php_block(): void
    {
        $reply = "Here is the fix:\n\n```php\n<?php\necho 'hello';\n```\n\nLet me know if that works.";

        $result = $this->service->extractCodeBlock($reply);

        $this->assertNotNull($result);
        $this->assertSame('php', $result['language']);
        $this->assertStringContainsString("echo 'hello';", $result['code']);
    }

    public function test_extract_code_block_normalizes_javascript_alias_to_node(): void
    {
        $reply = "```javascript\nconsole.log('hi');\n```";

        $result = $this->service->extractCodeBlock($reply);

        $this->assertNotNull($result);
        $this->assertSame('node', $result['language']);
    }

    public function test_extract_code_block_returns_null_for_unsupported_language(): void
    {
        $reply = "```ruby\nputs 'hi'\n```";

        $this->assertNull($this->service->extractCodeBlock($reply));
    }

    public function test_correction_prompt_includes_the_real_stderr(): void
    {
        $execution = new AiCodeExecution([
            'exit_code' => 1,
            'stderr' => 'ParseError: unexpected token on line 3',
        ]);

        $prompt = $this->service->correctionPrompt($execution);

        $this->assertStringContainsString('ParseError: unexpected token on line 3', $prompt);
        $this->assertStringContainsString('Exit code: 1', $prompt);
    }
}
