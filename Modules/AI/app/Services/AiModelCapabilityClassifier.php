<?php

namespace Modules\AI\Services;

use Illuminate\Support\Str;
use Modules\AI\Enums\AiModelCapability;
use Modules\AI\Models\AiProvider;

/**
 * Business gap fix: OpenAI's (and every other provider's) real /v1/models
 * endpoint returns just an id, not what the model actually does - no
 * "supports vision" or "reasoning" flag anywhere in it. AiProviderModelSyncService's
 * capability tagging is therefore only ever a naming-pattern guess
 * ("gpt-4o" looks multimodal, "o3" looks like a reasoning model, ...),
 * good enough as a starting point but still a guess - and a wrong one is
 * exactly what caused gpt-5-codex to get mis-tagged "vision" and picked
 * for an image message it could never actually see.
 *
 * This asks the provider's own AI model to classify its sibling models
 * instead of relying purely on string matching - a second AI call, in
 * the same spirit as AiVerificationEngine's "ask the model to grade the
 * model" pattern, whose real answer is grounded in what it actually
 * knows about each named model rather than a hand-written keyword list.
 * Still not a database guarantee (the classifier can be wrong too), but
 * it is the closest thing to "ask OpenAI" that a /v1/models-only API
 * allows - explicitly surfaced as an admin-triggered action (not run
 * silently on every sync) so a wrong verdict is something the admin
 * asked for and can review/correct via the existing per-model checkboxes,
 * never something that silently overwrites a manually-corrected tag.
 */
class AiModelCapabilityClassifier
{
    public function __construct(protected AiGateway $gateway) {}

    /**
     * @param  list<string>  $modelKeys
     * @return array{success: bool, message: ?string, capabilities: array<string, list<string>>}
     */
    public function classify(AiProvider $provider, array $modelKeys): array
    {
        if ($modelKeys === []) {
            return ['success' => true, 'message' => null, 'capabilities' => []];
        }

        // Ask whichever model the provider already treats as its working
        // default to classify its siblings, never a model from the very
        // list being classified (it could be the deprecated/mistagged one
        // this whole feature exists to correct).
        $classifierModelKey = $provider->defaultRegisteredModel()?->model_key ?? $provider->model;

        if (! $classifierModelKey) {
            return ['success' => false, 'message' => __('ai.classification_no_working_model'), 'capabilities' => []];
        }

        $classifierProvider = tap(clone $provider, fn (AiProvider $p) => $p->model = $classifierModelKey);

        try {
            $result = $this->gateway->chat($classifierProvider, $this->buildMessages($modelKeys));
        } catch (\Throwable $e) {
            report($e);

            return ['success' => false, 'message' => __('ai.classification_failed'), 'capabilities' => []];
        }

        if (! $result['success'] || blank($result['content'])) {
            return ['success' => false, 'message' => $result['message'] ?? __('ai.classification_failed'), 'capabilities' => []];
        }

        $parsed = $this->parseCapabilities($result['content'], $modelKeys);

        if ($parsed === null) {
            return ['success' => false, 'message' => __('ai.classification_unparseable'), 'capabilities' => []];
        }

        return ['success' => true, 'message' => null, 'capabilities' => $parsed];
    }

    /**
     * @param  list<string>  $modelKeys
     * @return list<array{role: string, content: string}>
     */
    protected function buildMessages(array $modelKeys): array
    {
        $vocabulary = implode(', ', AiModelCapability::values());
        $list = implode("\n", array_map(fn (string $key) => "- {$key}", $modelKeys));

        $instructions = <<<PROMPT
You are classifying AI model ids by what they can actually do, from your own
knowledge of these models - not by guessing from the id string alone.

Allowed capability tags (use ONLY these, spelled exactly like this): {$vocabulary}

For every model id listed below, give the full set of capability tags that
model genuinely has. Rules:
- Every model gets "chat" unless it truly cannot do general text chat at all.
- "vision" only if that exact model can accept image input, not just because
  it shares a name prefix with a model that can (a code-specialized or
  audio-specialized variant of a vision-capable family usually cannot).
- "reasoning" only for models specifically built for deep multi-step
  reasoning (the o1/o3/o4 family, gpt-5's reasoning-tier variants, etc.),
  not just because a model is generally capable.
- "coding" for models built or strongly specialized for code.
- A model id containing "gpt-image" or "dall-e" is a dedicated
  image-generation endpoint, not reachable through chat/completions at
  all: it cannot chat and cannot see an attached image conversationally.
  Tag it with ONLY "image_generation" - never "chat", never "vision".
- If you do not actually recognize a model id, still return it with your
  best honest guess based on its naming family - never omit a requested id.

Models to classify:
{$list}

Respond with ONLY a single JSON object, no markdown fences, no commentary,
mapping each model id exactly as given to an array of its capability tags:
{"model-id-1": ["chat", "vision"], "model-id-2": ["chat", "coding"]}
PROMPT;

        return [
            ['role' => 'system', 'content' => $instructions],
            ['role' => 'user', 'content' => 'Return the JSON classification now.'],
        ];
    }

    /**
     * @param  list<string>  $requestedKeys
     * @return ?array<string, list<string>>
     */
    protected function parseCapabilities(string $raw, array $requestedKeys): ?array
    {
        $cleaned = trim($raw);
        $cleaned = preg_replace('/^```(?:json)?/i', '', $cleaned);
        $cleaned = preg_replace('/```$/', '', trim($cleaned));
        $cleaned = trim($cleaned);

        if (! Str::startsWith($cleaned, '{')) {
            if (! preg_match('/\{.*\}/s', $cleaned, $matches)) {
                return null;
            }

            $cleaned = $matches[0];
        }

        $decoded = json_decode($cleaned, true);

        if (! is_array($decoded)) {
            return null;
        }

        $validTags = AiModelCapability::values();
        $result = [];

        // Only requested model_keys are ever applied - a classifier reply
        // inventing or renaming an id (or wrapping the map in an extra
        // layer) can never make this write capabilities onto a model
        // nobody actually asked to reclassify.
        foreach ($requestedKeys as $modelKey) {
            // Real, observed gap: the classifier (and the force-added
            // "chat" below) tagged gpt-image-1/dall-e-3/etc as
            // "chat, vision, image_generation" - plausible from the model
            // name alone, but these are image-generation-only endpoints
            // that OpenAI rejects outright on a chat/completions call.
            // Enforced here exactly like AiProviderModelSyncService's own
            // registration path, so no classifier answer (however
            // confident) can ever re-tag one of these as chat- or
            // vision-capable.
            if (AiProviderModelSyncService::isImageGenerationModel($modelKey)) {
                $result[$modelKey] = [AiModelCapability::ImageGeneration->value];

                continue;
            }

            // Same gap, same fix, for the two categories added alongside
            // real transcribeAudio()/synthesizeSpeech() connector calls:
            // whisper-1/tts-1-style ids read as plausible "chat" models to
            // the classifier too, but /audio/transcriptions and
            // /audio/speech reject a chat/completions-style call outright.
            // Force their real capability instead of trusting (or
            // force-adding "chat" to) the classifier's answer.
            if (AiProviderModelSyncService::isSpeechToTextModel($modelKey)) {
                $result[$modelKey] = [AiModelCapability::SpeechToText->value];

                continue;
            }

            if (AiProviderModelSyncService::isTextToSpeechModel($modelKey)) {
                $result[$modelKey] = [AiModelCapability::TextToSpeech->value];

                continue;
            }

            $tags = $decoded[$modelKey] ?? null;

            if (! is_array($tags)) {
                continue;
            }

            $filtered = array_values(array_intersect(array_unique($tags), $validTags));

            if (! in_array(AiModelCapability::Chat->value, $filtered, true)) {
                array_unshift($filtered, AiModelCapability::Chat->value);
            }

            // Real, observed gap: the classifier is a live AI call asked
            // to self-report from its own knowledge, and that self-report
            // can simply be wrong - an observed real case tagged
            // gpt-4o-mini "chat, reasoning" and left out "vision"
            // entirely, which silently broke every image message routed
            // to it (AiGateway strips the attached image before the API
            // call whenever the routed model isn't tagged "vision"). A
            // naming-pattern family AiProviderModelSyncService is already
            // confident is multimodal (gpt-4o, gpt-4.1, gpt-5, Claude 3+,
            // Gemini, ...) must never lose "vision" to one uncertain AI
            // answer - forced back in here exactly like the image-
            // generation/speech-to-text/text-to-speech overrides above,
            // never removed if the classifier already included it.
            if (AiProviderModelSyncService::isKnownMultimodalModel($modelKey)
                && ! in_array(AiModelCapability::Vision->value, $filtered, true)) {
                $filtered[] = AiModelCapability::Vision->value;
            }

            $result[$modelKey] = $filtered;
        }

        return $result === [] ? null : $result;
    }
}
