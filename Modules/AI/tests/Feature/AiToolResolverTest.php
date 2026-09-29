<?php

namespace Modules\AI\Tests\Feature;

use Modules\AI\Services\AiToolResolver;
use Tests\TestCase;

/**
 * Master spec section 12/48-49 - a real, deterministic keyword trigger
 * for the AiToolInterface/AiToolRegistry pipeline (see AiToolResolver's
 * own docblock for why this is deliberately keyword-based, not the
 * model's native function-calling loop). Extends the Laravel TestCase
 * (not plain PHPUnit, unlike AiRequiredCapabilityResolverTest) because
 * resolve() reads config('ai.tools.*'), which needs the app container.
 */
class AiToolResolverTest extends TestCase
{
    public function test_an_egyptian_arabic_usage_question_matches_the_usage_status_tool(): void
    {
        $matched = app(AiToolResolver::class)->resolve('كام رسالة باقيلي النهارده؟');

        $this->assertContains('usage_status', $matched);
    }

    public function test_an_english_usage_question_matches_the_usage_status_tool(): void
    {
        $matched = app(AiToolResolver::class)->resolve('how many messages do I have left today?');

        $this->assertContains('usage_status', $matched);
    }

    public function test_an_unrelated_message_matches_no_tool(): void
    {
        $matched = app(AiToolResolver::class)->resolve('ما هو Laravel؟');

        $this->assertSame([], $matched);
    }

    public function test_nothing_matches_when_tools_are_disabled(): void
    {
        config(['ai.tools.enabled' => false]);

        $matched = app(AiToolResolver::class)->resolve('كام رسالة باقيلي؟');

        $this->assertSame([], $matched);
    }
}
