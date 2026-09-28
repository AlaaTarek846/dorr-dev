<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiProvider;
use Modules\AI\Repositories\AiProviderRepository;
use Modules\AI\Services\AiRoutingEngine;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Business gap fix: routing used to only ever look at plain message text,
 * never at what the message actually needs (a vision-capable model for an
 * attached image, say), and always ended up on the single "active"
 * provider regardless of whether it could even do the job. This proves
 * AiRoutingEngine now prefers - across every connected provider, not just
 * the default one - whichever registered ai_provider_models row actually
 * has the required capability.
 */
class AiRoutingEngineCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Routing Test User',
            'email' => 'routing-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    public function test_a_plain_text_message_uses_the_default_provider_with_no_capability_override(): void
    {
        $repository = app(AiProviderRepository::class);
        $repository->updateByKey('openai', ['is_enabled' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);
        $repository->setDefault('openai');

        $engine = app(AiRoutingEngine::class);
        $result = $engine->resolve($this->makeUser(), 'ازيك عامل ايه', null, $repository);

        $this->assertSame('openai', $result['candidates'][0]['provider']->key);
        $this->assertSame([], $result['required_capabilities']);
    }

    /**
     * Root-cause fix - real, observed bug: a plain, ordinary chat message
     * (no attachment, no special capability keyword) has
     * required_capabilities === [], so applyCapabilityPreferences() calls
     * AiProvider::defaultRegisteredModel() directly. That method's
     * fallback used to be a bare "first active model, whatever it is"
     * with no capability filter, so an image-generation-only model
     * (registered active but deliberately never is_default by
     * AiProviderModelSyncService) could get silently picked as "the
     * default chat model" for EVERY ordinary text message, which OpenAI
     * then rejected outright ("This is not a chat model..."). This proves
     * routing a plain text message always resolves to the real
     * chat-capable model, never the image-only one, even when neither
     * row is explicitly marked is_default and the image-only row was
     * registered first.
     */
    public function test_a_plain_text_message_never_resolves_to_an_image_only_model_even_with_no_explicit_default(): void
    {
        $repository = app(AiProviderRepository::class);
        $openai = $repository->updateByKey('openai', ['is_enabled' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);
        $repository->setDefault('openai');

        // Image-only model registered FIRST, real chat model second, and
        // neither is marked is_default - exactly the real-world state
        // that triggered the bug.
        $openai->models()->create([
            'model_key' => 'gpt-image-2.5-flare',
            'display_name' => 'GPT Image 2.5 Flare',
            'capabilities' => ['image_generation'],
            'is_default' => false,
            'is_active' => true,
        ]);

        $openai->models()->create([
            'model_key' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o mini',
            'capabilities' => ['chat', 'vision'],
            'is_default' => false,
            'is_active' => true,
        ]);

        $engine = app(AiRoutingEngine::class);
        $result = $engine->resolve($this->makeUser(), 'ازيك عامل ايه', null, $repository);

        $this->assertSame('openai', $result['candidates'][0]['provider']->key);
        $this->assertSame('gpt-4o-mini', $result['candidates'][0]['model_key']);
    }

    public function test_an_image_attachment_prefers_a_vision_capable_model_over_the_plain_default(): void
    {
        $repository = app(AiProviderRepository::class);

        $openai = $repository->updateByKey('openai', ['is_enabled' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);
        $repository->setDefault('openai');
        // OpenAI is the default, but has no registered models at all yet -
        // it can only fall back to its legacy single $model column, which
        // is not tagged vision anywhere.

        $groq = $repository->updateByKey('groq', ['is_enabled' => true, 'api_key' => 'gsk-test', 'model' => 'llama-3.3-70b']);
        $groq->models()->create([
            'model_key' => 'llama-vision-preview',
            'display_name' => 'Llama Vision',
            'capabilities' => ['chat', 'vision'],
            'is_default' => false,
            'is_active' => true,
        ]);

        $engine = app(AiRoutingEngine::class);
        $result = $engine->resolve($this->makeUser(), 'وريني ايه اللي في الصورة دي', null, $repository, 'image/png');

        $this->assertSame(['vision'], $result['required_capabilities']);
        $this->assertSame('groq', $result['candidates'][0]['provider']->key);
        $this->assertSame('llama-vision-preview', $result['candidates'][0]['model_key']);
        $this->assertTrue($result['candidates'][0]['capability_matched']);

        // The originally-default provider is still in the fallback chain,
        // just no longer first - a real failure of the vision-capable
        // provider still has somewhere to fall back to.
        $this->assertContains(
            'openai',
            array_map(fn (array $candidate) => $candidate['provider']->key, $result['candidates']),
        );
    }

    public function test_when_nothing_registered_has_the_capability_it_degrades_to_the_normal_candidates(): void
    {
        $repository = app(AiProviderRepository::class);
        $repository->updateByKey('openai', ['is_enabled' => true, 'api_key' => 'sk-test', 'model' => 'gpt-4o-mini']);
        $repository->setDefault('openai');

        $engine = app(AiRoutingEngine::class);
        $result = $engine->resolve($this->makeUser(), 'وريني ايه اللي في الصورة دي', null, $repository, 'image/png');

        $this->assertSame(['vision'], $result['required_capabilities']);
        $this->assertNotEmpty($result['candidates']);
        $this->assertSame('openai', $result['candidates'][0]['provider']->key);
        $this->assertFalse($result['candidates'][0]['capability_matched']);
    }
}
