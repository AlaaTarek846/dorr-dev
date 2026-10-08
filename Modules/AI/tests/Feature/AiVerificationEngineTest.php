<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiProvider;
use Modules\AI\Services\AiGateway;
use Modules\AI\Services\AiVerificationEngine;
use Tests\TestCase;

/**
 * v2.0 requirements doc S6: confidence must come from an explicit,
 * documented formula over real verification results - never a fixed or
 * self-rated number. These tests exercise AiVerificationEngine::score()
 * (via the public verify() entrypoint, with AiGateway mocked at the
 * network boundary) against hand-computed expected values, the same way
 * AiPiiSanitizer's regex bugs were caught earlier in this engagement:
 * by checking the actual arithmetic the code produces, not just that it
 * runs.
 */
class AiVerificationEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function makeProvider(): AiProvider
    {
        return AiProvider::query()->create([
            'key' => 'openai',
            'name' => 'Verifier (test)',
            'is_enabled' => true,
            'api_key' => 'test-key-not-real',
            'model' => 'gpt-4o-mini',
        ]);
    }

    protected function mockVerdict(array $verdict): void
    {
        $this->mock(AiGateway::class, function ($mock) use ($verdict) {
            $mock->shouldReceive('chat')->once()->andReturn([
                'success' => true,
                'message' => 'ok',
                'content' => json_encode($verdict),
            ]);
        });
    }

    public function test_engine_error_when_the_gateway_call_fails(): void
    {
        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn([
                'success' => false,
                'message' => 'timeout',
                'content' => null,
            ]);
        });

        $result = app(AiVerificationEngine::class)->verify($this->makeProvider(), 'question', 'draft');

        $this->assertTrue($result['engine_error']);
        $this->assertNull($result['confidence_score']);
    }

    public function test_engine_error_when_the_verifier_response_is_not_valid_json(): void
    {
        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn([
                'success' => true,
                'message' => 'ok',
                'content' => 'I refuse to answer in JSON, sorry.',
            ]);
        });

        $result = app(AiVerificationEngine::class)->verify($this->makeProvider(), 'question', 'draft');

        $this->assertTrue($result['engine_error']);
    }

    public function test_verdict_wrapped_in_markdown_fences_is_still_parsed(): void
    {
        $verdict = [
            'claims' => [],
            'completeness' => 0.8,
            'contradictions' => [],
            'issues' => [],
        ];

        $this->mock(AiGateway::class, function ($mock) use ($verdict) {
            $mock->shouldReceive('chat')->once()->andReturn([
                'success' => true,
                'message' => 'ok',
                'content' => "```json\n".json_encode($verdict)."\n```",
            ]);
        });

        $result = app(AiVerificationEngine::class)->verify($this->makeProvider(), 'question', 'draft');

        $this->assertFalse($result['engine_error']);
    }

    public function test_all_claims_supported_yields_the_hand_computed_confidence_score(): void
    {
        $this->mockVerdict([
            'claims' => [
                ['claim' => 'Claim A', 'supported' => true, 'confidence' => 0.9],
                ['claim' => 'Claim B', 'supported' => true, 'confidence' => 0.8],
            ],
            'completeness' => 0.9,
            'contradictions' => [],
            'issues' => [],
        ]);

        $result = app(AiVerificationEngine::class)->verify($this->makeProvider(), 'q', 'draft');

        // supportedRatio=1.0, evidenceStrength=avg(0.9,0.8)=0.85, completeness=0.9
        // confidence = 1.0*0.5 + 0.85*0.3 + 0.9*0.2 = 0.935
        $this->assertFalse($result['engine_error']);
        $this->assertSame(1.0, $result['supported_claims_ratio']);
        $this->assertSame(0.85, $result['evidence_strength']);
        $this->assertSame(0.9, $result['completeness_score']);
        $this->assertSame(0.935, $result['confidence_score']);
    }

    public function test_an_unsupported_claim_pulls_the_confidence_score_down(): void
    {
        $this->mockVerdict([
            'claims' => [
                ['claim' => 'Claim A', 'supported' => false, 'confidence' => 0.3],
            ],
            'completeness' => 0.5,
            'contradictions' => [],
            'issues' => ['The claim could not be verified against any source.'],
        ]);

        $result = app(AiVerificationEngine::class)->verify($this->makeProvider(), 'q', 'draft');

        // supportedRatio=0.0, evidenceStrength=0.3, completeness=0.5
        // confidence = 0*0.5 + 0.3*0.3 + 0.5*0.2 = 0.19
        $this->assertSame(0.0, $result['supported_claims_ratio']);
        $this->assertSame(0.19, $result['confidence_score']);
        $this->assertCount(1, $result['issues']);
    }

    public function test_a_contradiction_caps_confidence_at_0_55_even_if_the_weighted_score_is_higher(): void
    {
        $this->mockVerdict([
            'claims' => [
                ['claim' => 'Claim A', 'supported' => true, 'confidence' => 1.0],
            ],
            'completeness' => 1.0,
            'contradictions' => ['States the price is $10 in one place and $20 in another.'],
            'issues' => [],
        ]);

        $result = app(AiVerificationEngine::class)->verify($this->makeProvider(), 'q', 'draft');

        // Uncapped weighted confidence would be 1.0*0.5+1.0*0.3+1.0*0.2 = 1.0,
        // but a contradiction must cap it at 0.55 regardless.
        $this->assertSame(0.55, $result['confidence_score']);
        $this->assertContains('States the price is $10 in one place and $20 in another.', $result['issues']);
    }

    public function test_a_draft_with_no_checkable_claims_defaults_support_and_evidence_to_full_and_scores_on_completeness(): void
    {
        $this->mockVerdict([
            'claims' => [],
            'completeness' => 0.8,
            'contradictions' => [],
            'issues' => [],
        ]);

        $result = app(AiVerificationEngine::class)->verify($this->makeProvider(), 'hi there', 'Hello! How can I help you today?');

        // No claims -> supportedRatio and evidenceStrength both default to
        // 1.0 (nothing to be wrong about); confidence rests on completeness.
        // confidence = 1.0*0.5 + 1.0*0.3 + 0.8*0.2 = 0.96
        $this->assertSame(1.0, $result['supported_claims_ratio']);
        $this->assertSame(1.0, $result['evidence_strength']);
        $this->assertSame(0.96, $result['confidence_score']);
    }

    public function test_confidence_and_claim_confidence_values_are_clamped_into_the_0_to_1_range(): void
    {
        $this->mockVerdict([
            'claims' => [
                ['claim' => 'Claim A', 'supported' => true, 'confidence' => 1.7],
                ['claim' => 'Claim B', 'supported' => true, 'confidence' => -0.4],
            ],
            'completeness' => 5.0,
            'contradictions' => [],
            'issues' => [],
        ]);

        $result = app(AiVerificationEngine::class)->verify($this->makeProvider(), 'q', 'draft');

        $this->assertSame(1.0, $result['claims'][0]['confidence']);
        $this->assertSame(0.0, $result['claims'][1]['confidence']);
        $this->assertSame(1.0, $result['completeness_score']);
        $this->assertLessThanOrEqual(1.0, $result['confidence_score']);
    }

    public function test_a_claim_with_an_empty_claim_text_is_dropped(): void
    {
        $this->mockVerdict([
            'claims' => [
                ['claim' => '', 'supported' => true, 'confidence' => 0.9],
                ['claim' => 'Real claim', 'supported' => true, 'confidence' => 0.9],
            ],
            'completeness' => 0.9,
            'contradictions' => [],
            'issues' => [],
        ]);

        $result = app(AiVerificationEngine::class)->verify($this->makeProvider(), 'q', 'draft');

        $this->assertCount(1, $result['claims']);
        $this->assertSame('Real claim', $result['claims'][0]['claim']);
    }

    /**
     * v2.0 requirements doc S20.3 (failure/chaos for critical paths). A
     * real gap this caught: verify() called AiGateway::chat() with no
     * try/catch, so a throwable from the gateway (the same
     * unsupported-provider-key RuntimeException fixed in
     * AiChatService::dispatchWithFallback()) would crash the whole
     * sendMessage() request from inside the verification loop instead of
     * degrading to the engine_error result the rest of the verification
     * loop already knows how to handle gracefully.
     */
    public function test_a_throwing_gateway_call_degrades_to_an_engine_error_instead_of_crashing(): void
    {
        $this->mock(AiGateway::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(new \RuntimeException('Unsupported AI provider [openai].'));
        });

        $result = app(AiVerificationEngine::class)->verify($this->makeProvider(), 'q', 'draft');

        $this->assertTrue($result['engine_error']);
        $this->assertNull($result['confidence_score']);
    }
}
