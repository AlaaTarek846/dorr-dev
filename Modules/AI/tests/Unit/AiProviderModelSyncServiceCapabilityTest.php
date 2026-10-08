<?php

namespace Modules\AI\Tests\Unit;

use Modules\AI\Services\AiProviderModelSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Real, observed bug: a "-codex" model id (e.g. gpt-5-codex) used to be
 * tagged "vision" just because it also starts with "gpt-5", the same
 * prefix every genuinely multimodal gpt-5 model has. That let
 * AiProvider::bestModelFor() hand an image message to a code-only model
 * that cannot see the picture at all. Pure PHP, no DB/framework needed -
 * exercises the protected inferCapabilities() method directly via
 * reflection, the same pattern used for AiDocumentTextExtractorTest.
 */
class AiProviderModelSyncServiceCapabilityTest extends TestCase
{
    protected function capabilitiesFor(string $modelId): array
    {
        $method = new ReflectionMethod(AiProviderModelSyncService::class, 'inferCapabilities');
        $method->setAccessible(true);

        return $method->invoke(new AiProviderModelSyncService, $modelId);
    }

    protected function isChatCapable(string $modelId): bool
    {
        $method = new ReflectionMethod(AiProviderModelSyncService::class, 'isChatCapable');
        $method->setAccessible(true);

        return $method->invoke(new AiProviderModelSyncService, $modelId);
    }

    public function test_a_codex_model_is_tagged_coding_but_never_vision(): void
    {
        $capabilities = $this->capabilitiesFor('gpt-5-codex');

        $this->assertContains('coding', $capabilities);
        $this->assertNotContains('vision', $capabilities);
    }

    public function test_a_genuine_gpt5_chat_model_is_still_tagged_vision(): void
    {
        $capabilities = $this->capabilitiesFor('gpt-5');

        $this->assertContains('vision', $capabilities);
    }

    public function test_gpt4o_is_still_tagged_vision(): void
    {
        $capabilities = $this->capabilitiesFor('gpt-4o');

        $this->assertContains('vision', $capabilities);
    }

    public function test_a_reasoning_model_is_tagged_reasoning_not_vision(): void
    {
        $capabilities = $this->capabilitiesFor('o3-mini');

        $this->assertContains('reasoning', $capabilities);
        $this->assertNotContains('vision', $capabilities);
    }

    /**
     * Real, observed bug: OpenAI's real /v1/models list includes models
     * such as "gpt-4o-audio-preview" and "gpt-4o-realtime-preview" (and
     * their "gpt-audio"/"gpt-realtime" successors) which ARE served from
     * the chat/completions family, so nothing used to filter them out of
     * auto-registration - but OpenAI rejects a plain text-only call to
     * them with "This model requires that either input content or
     * output modality contain audio.", which is exactly the error that
     * surfaced when one of these got auto-registered, picked as the
     * provider's default, and then used as the "Reclassify with AI"
     * classifier model.
     */
    public function test_audio_and_realtime_preview_models_are_never_chat_capable(): void
    {
        $this->assertFalse($this->isChatCapable('gpt-4o-audio-preview'));
        $this->assertFalse($this->isChatCapable('gpt-4o-mini-audio-preview'));
        $this->assertFalse($this->isChatCapable('gpt-4o-realtime-preview'));
        $this->assertFalse($this->isChatCapable('gpt-4o-mini-realtime-preview'));
        $this->assertFalse($this->isChatCapable('gpt-audio'));
        $this->assertFalse($this->isChatCapable('gpt-audio-mini'));
        $this->assertFalse($this->isChatCapable('gpt-realtime'));
    }

    public function test_an_ordinary_text_chat_model_is_still_chat_capable(): void
    {
        $this->assertTrue($this->isChatCapable('gpt-4o'));
        $this->assertTrue($this->isChatCapable('gpt-4o-mini'));
    }

    /**
     * Real, observed bug: "gpt-4o-mini-search-preview-2025-03-11" (a
     * text + web-search-augmented variant, no image input support at
     * all) still starts with "gpt-4o" like every genuinely multimodal
     * gpt-4o model, so it inherited "vision" the same way gpt-5-codex
     * used to inherit it from the "gpt-5" prefix - and OpenAI regularly
     * deprecates these dated search-preview snapshots, so a stale one
     * being routed to for an image message is doubly wrong.
     */
    public function test_a_search_preview_variant_is_never_tagged_vision(): void
    {
        $capabilities = $this->capabilitiesFor('gpt-4o-mini-search-preview-2025-03-11');

        $this->assertNotContains('vision', $capabilities);
        $this->assertContains('chat', $capabilities);
    }

    public function test_a_search_preview_variant_is_still_registered_as_chat_capable(): void
    {
        $this->assertTrue($this->isChatCapable('gpt-4o-mini-search-preview-2025-03-11'));
    }

    /**
     * Phase 8 completion: a registered "-search-preview"/"-search-api"
     * model is text+web-search-augmented, not vision - covered above -
     * but until now nothing ever tagged it with the "web_search"
     * capability it actually has either, so AiRoutingEngine could never
     * match it for a "سعر الدولار اليوم"/"latest news" request; it would
     * only ever be picked for a plain chat turn like any other model.
     */
    public function test_a_search_preview_variant_is_tagged_web_search(): void
    {
        $capabilities = $this->capabilitiesFor('gpt-4o-mini-search-preview-2025-03-11');

        $this->assertContains('web_search', $capabilities);
    }

    /**
     * Real, observed gap: only the older "-search-preview" naming was
     * ever excluded from isKnownMultimodalModel()'s vision check -
     * "gpt-5-search-api" (the newer naming) still starts with "gpt-5"
     * like every genuinely multimodal gpt-5 model and fell straight
     * through to being wrongly tagged vision, the exact same class of
     * bug "-search-preview" and "-codex" were already fixed for.
     */
    public function test_a_search_api_variant_is_tagged_web_search(): void
    {
        $capabilities = $this->capabilitiesFor('gpt-5-search-api');

        $this->assertContains('web_search', $capabilities);
        $this->assertNotContains('vision', $capabilities);
    }

    public function test_an_ordinary_chat_model_is_not_tagged_web_search(): void
    {
        $capabilities = $this->capabilitiesFor('gpt-4o');

        $this->assertNotContains('web_search', $capabilities);
    }
}
