<?php

namespace Modules\AI\Tests\Unit\Chunking;

use Modules\AI\Services\Chunking\AiHeuristicTokenCounter;
use PHPUnit\Framework\TestCase;

/**
 * Phase 8 (doc S14): tests the estimator as an estimator - asserting
 * "estimated token count > 0" / a sane ratio, never a fake exact
 * count, since no real tokenizer backs this (isExact() must be false).
 */
class AiHeuristicTokenCounterTest extends TestCase
{
    public function test_is_exact_is_always_false(): void
    {
        $this->assertFalse((new AiHeuristicTokenCounter)->isExact());
    }

    public function test_estimate_is_positive_for_non_empty_text(): void
    {
        $counter = new AiHeuristicTokenCounter;

        $this->assertGreaterThan(0, $counter->estimateTokenCount('Hello world, this is a test sentence.'));
    }

    public function test_estimate_is_zero_for_empty_text(): void
    {
        $this->assertSame(0, (new AiHeuristicTokenCounter)->estimateTokenCount(''));
    }

    public function test_estimate_scales_with_length_not_a_constant(): void
    {
        $counter = new AiHeuristicTokenCounter;

        $short = $counter->estimateTokenCount('short text');
        $long = $counter->estimateTokenCount(str_repeat('a much longer piece of text ', 50));

        $this->assertGreaterThan($short, $long);
    }

    public function test_estimate_handles_arabic_text_without_error(): void
    {
        $counter = new AiHeuristicTokenCounter;

        $count = $counter->estimateTokenCount('هذا نص عربي للاختبار يحتوي على عدة كلمات.');

        $this->assertGreaterThan(0, $count);
    }
}
