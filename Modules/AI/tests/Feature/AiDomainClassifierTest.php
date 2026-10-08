<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiDomainPolicy;
use Modules\AI\Services\AiDomainClassifier;
use Tests\TestCase;

/**
 * v2.0 requirements doc §7-14/§20.1: covers AiDomainClassifier's
 * keyword-to-policy resolution against real ai_domain_policies rows.
 */
class AiDomainClassifierTest extends TestCase
{
    use RefreshDatabase;

    protected AiDomainClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new AiDomainClassifier;
    }

    protected function makePolicy(string $domainKey): AiDomainPolicy
    {
        return AiDomainPolicy::query()->create([
            'domain_key' => $domainKey,
            'name' => $domainKey,
            'risk_level' => AiDomainPolicy::RISK_MEDIUM,
            'is_active' => true,
        ]);
    }

    public function test_classifies_a_health_keyword_message(): void
    {
        $this->makePolicy(AiDomainPolicy::DOMAIN_HEALTH);

        $policy = $this->classifier->classify('عندي حمى وأعراض غريبة من يومين');

        $this->assertNotNull($policy);
        $this->assertSame(AiDomainPolicy::DOMAIN_HEALTH, $policy->domain_key);
    }

    public function test_classifies_a_legal_keyword_message(): void
    {
        $this->makePolicy(AiDomainPolicy::DOMAIN_LEGAL);

        $policy = $this->classifier->classify('عايز اعرف حقوقي القانونية في العقد ده');

        $this->assertNotNull($policy);
        $this->assertSame(AiDomainPolicy::DOMAIN_LEGAL, $policy->domain_key);
    }

    public function test_classifies_a_code_keyword_message(): void
    {
        $this->makePolicy(AiDomainPolicy::DOMAIN_CODE);

        $policy = $this->classifier->classify('اكتب لي function بلغة PHP');

        $this->assertNotNull($policy);
        $this->assertSame(AiDomainPolicy::DOMAIN_CODE, $policy->domain_key);
    }

    public function test_falls_back_to_general_info_when_nothing_matches(): void
    {
        $this->makePolicy(AiDomainPolicy::DOMAIN_GENERAL_INFO);

        $policy = $this->classifier->classify('ايه احسن مطعم في القاهرة؟');

        $this->assertNotNull($policy);
        $this->assertSame(AiDomainPolicy::DOMAIN_GENERAL_INFO, $policy->domain_key);
    }

    public function test_returns_null_when_no_matching_policy_is_active(): void
    {
        // No policies seeded at all - classify() must not throw, and
        // must return null rather than a phantom policy.
        $policy = $this->classifier->classify('عندي وجع في بطني');

        $this->assertNull($policy);
    }

    public function test_ignores_an_inactive_policy(): void
    {
        AiDomainPolicy::query()->create([
            'domain_key' => AiDomainPolicy::DOMAIN_HEALTH,
            'name' => 'health',
            'risk_level' => AiDomainPolicy::RISK_MEDIUM,
            'is_active' => false,
        ]);

        $policy = $this->classifier->classify('عندي حمى وأعراض غريبة');

        $this->assertNull($policy);
    }
}
