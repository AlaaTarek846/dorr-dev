<?php

namespace Modules\AI\Services;

use Illuminate\Support\Str;
use Modules\AI\Enums\AiModelCapability;
use Modules\AI\Enums\AiModelCategory;
use Modules\AI\Models\AiProvider;

/**
 * Business gap fix: a successful "test connection" already calls the
 * provider's real /models API and caches the flat id list on
 * AiProvider::available_models (see each connector's testConnection()) -
 * but that list only ever fed a plain dropdown. The admin still had to
 * manually re-type every single model_key into the "connected models"
 * section (ai_provider_models) one at a time, including its capability
 * checkboxes, even though the platform had literally just been told the
 * exact list of models the account can use. This turns that already-
 * fetched list straight into registered rows, so testing the connection
 * is enough - "connect and test" now really does mean "connected to
 * every model I have", matching what was asked for.
 *
 * Deliberately conservative:
 * - Only genuinely chat-completion-capable models are registered.
 *   Speech-to-text, text-to-speech, moderation, embedding and
 *   image-generation-only models are skipped outright: AiGateway::chat()
 *   always calls the chat-completions endpoint, so registering one of
 *   these would let AiRoutingEngine pick a model that can never actually
 *   answer a chat message.
 * - Capability tags beyond "chat" are only added when the model id
 *   itself strongly implies them, from well-known naming patterns
 *   (vision/reasoning/coding). This is a best-effort starting point, not
 *   a guarantee - the admin can still add, remove or correct capabilities
 *   per model afterwards through the existing checkboxes, exactly as
 *   before this fix.
 * - A model_key already registered - active OR previously deactivated -
 *   is left untouched, so re-testing a connection never creates
 *   duplicates. Deactivating (not deleting) a model is therefore the way
 *   to permanently keep it out of future auto-syncs: a deleted row's
 *   model_key is simply "unknown" again and can reappear next time the
 *   provider is tested, exactly like a brand-new model would.
 */
class AiProviderModelSyncService
{
    /**
     * A model id containing any of these is never a chat-completion
     * model, whatever provider it came from.
     *
     * @var list<string>
     */
    protected const NON_CHAT_MARKERS = [
        'whisper', 'tts', 'orpheus', 'guard', 'safeguard', 'moderation',
        'embedding', 'dall-e', 'davinci-002', 'babbage-002', 'text-moderation',
        'image-generation', 'imagen',
        // Real, observed gap: OpenAI's /v1/models list also includes
        // models such as "gpt-4o-audio-preview" and "gpt-4o-realtime-preview"
        // (and their "gpt-audio"/"gpt-realtime" successors) - these ARE
        // served from the chat/completions family, so nothing above
        // filtered them out, but OpenAI rejects a plain text-only call to
        // them with "This model requires that either input content or
        // output modality contain audio." unless the request also sets
        // modalities/audio params this codebase's connector never sends
        // (no voice system exists yet - see the open gaps list). One of
        // these got auto-registered as a normal chat model and then
        // picked as the provider's default, which is exactly what broke
        // "Reclassify with AI" (it always calls the provider's default
        // working model). Excluded here the same way whisper/tts already
        // are, so future "test connection" syncs never register one as a
        // plain chat model again.
        'audio', 'realtime',
        // Real, observed gap - live OpenAI catalog check (Sept 2026):
        // "gpt-live-1" is a Realtime/voice-conversation model with
        // neither "audio" nor "realtime" in its id, so it slipped past
        // both markers above and would have been registered as an
        // ordinary chat-capable model - the exact same "not really a
        // chat model" trap this whole marker list exists to close.
        'live',
    ];

    /**
     * @param  list<string>  $modelIds  the flat ids the provider's real
     *                                  API just returned
     * @return array{created: int, skipped: int, updated: int, deprecated: int, reactivated: int, found: int}
     */
    public function sync(AiProvider $provider, array $modelIds): array
    {
        $found = count(array_filter($modelIds, fn ($id) => is_string($id) && $id !== ''));

        if ($modelIds === []) {
            return ['created' => 0, 'skipped' => 0, 'updated' => 0, 'deprecated' => 0, 'reactivated' => 0, 'found' => 0];
        }

        $existingRows = $provider->models()->get(['id', 'model_key', 'status', 'category', 'temperature_supported', 'context_window', 'max_output_tokens']);
        $alreadyKnown = $existingRows->pluck('model_key')->all();
        $nextSortOrder = (int) $provider->models()->max('sort_order') + 1;
        $hasDefault = $provider->models()->where('is_default', true)->exists();
        $chatModelCreated = false;
        $now = now();

        $created = 0;
        $skipped = 0;
        $updated = 0;
        $reactivated = 0;

        foreach ($modelIds as $modelId) {
            if (! is_string($modelId) || $modelId === '') {
                continue;
            }

            if (in_array($modelId, $alreadyKnown, true)) {
                // Root-cause fix (dynamic model registry, real gap):
                // re-testing a connection used to leave an already-known
                // model_key completely untouched forever, which is
                // exactly why a model that later disappeared from
                // OpenAI's own list could never be told apart from one
                // nobody had simply re-synced in a while - both looked
                // identical (a row that still exists). Every model still
                // reported by the provider now gets last_seen_at bumped,
                // and a previously-deprecated one that reappears is
                // reactivated - all without ever touching its
                // capabilities/is_default/temperature/etc, which stay
                // exactly whatever the admin already set.
                $row = $existingRows->firstWhere('model_key', $modelId);
                $wasDeprecated = $row->status === 'deprecated';

                $updatePayload = ['last_seen_at' => $now];

                if ($wasDeprecated) {
                    $updatePayload['status'] = 'active';
                    $updatePayload['is_active'] = true;
                    $updatePayload['deprecated_at'] = null;
                }

                // Real, observed gap: a model registered before this
                // registry rebuild (or in a batch this sync's own
                // classification rules didn't yet cover) has
                // category=null forever otherwise - re-testing the
                // connection bumped last_seen_at but never actually
                // classified it, so the admin screen kept showing it
                // uncategorized no matter how many times "Test
                // Connection" was clicked. Backfilled here ONLY when
                // category is still null - never overwrites a category
                // the admin (or an earlier sync) already assigned, the
                // same "never clobber what's already set" rule every
                // other field in this branch already follows.
                if ($row->category === null) {
                    $updatePayload['category'] = $this->inferCategory($modelId);
                    $updatePayload['needs_review'] = $updatePayload['category'] === AiModelCategory::Unknown->value;
                    $updatePayload['model_family'] = $this->parseModelFamily($modelId);

                    $snapshot = $this->parseSnapshot($modelId);
                    $aliasOf = $this->detectAlias($modelId, $modelIds);

                    $updatePayload['canonical_model_id'] = $snapshot['canonical_model_id'] ?? $aliasOf;
                    $updatePayload['is_alias'] = $aliasOf !== null;
                    $updatePayload['is_snapshot'] = $snapshot['is_snapshot'];
                    $updatePayload['release_date'] = $snapshot['release_date'];
                }

                // Same "only when never computed" guard, kept as its own
                // independent null-check (not folded into the block
                // above) because a row can perfectly well already have a
                // real category while this field is still null - it was
                // added in a later migration than category was.
                if ($row->temperature_supported === null) {
                    $updatePayload['temperature_supported'] = $this->supportsTemperature($modelId);
                }

                // Likewise independent: an admin may have hand-typed a
                // real published figure into one of these two without the
                // other, so each is only ever backfilled on its own if
                // still null - never overwrites a value already present,
                // whether it came from this table or from the admin.
                if ($row->context_window === null || $row->max_output_tokens === null) {
                    $limits = $this->knownLimits($modelId);

                    if ($row->context_window === null && $limits['context_window'] !== null) {
                        $updatePayload['context_window'] = $limits['context_window'];
                    }

                    if ($row->max_output_tokens === null && $limits['max_output_tokens'] !== null) {
                        $updatePayload['max_output_tokens'] = $limits['max_output_tokens'];
                    }
                }

                $provider->models()->where('id', $row->id)->update($updatePayload);

                $updated++;

                if ($wasDeprecated) {
                    $reactivated++;
                }

                continue;
            }

            $category = $this->inferCategory($modelId);
            $family = $this->parseModelFamily($modelId);
            $snapshot = $this->parseSnapshot($modelId);
            $aliasOf = $this->detectAlias($modelId, $modelIds);

            $registryFields = [
                'category' => $category,
                'needs_review' => $category === AiModelCategory::Unknown->value,
                'model_family' => $family,
                'canonical_model_id' => $snapshot['canonical_model_id'] ?? $aliasOf,
                'is_alias' => $aliasOf !== null,
                'is_snapshot' => $snapshot['is_snapshot'],
                'release_date' => $snapshot['release_date'],
                'status' => 'active',
                'last_seen_at' => $now,
            ];

            // Real image-editing models (see AiChatService::tryHandleImageEdit())
            // are registered too, but tagged "image_generation" ONLY, never
            // "chat" and never made the provider's default - they cannot
            // answer a normal chat/completions call at all, so letting one
            // become the default would silently break every ordinary text
            // message this provider handles.
            if ($this->isImageGenerationModel($modelId)) {
                $provider->models()->create($registryFields + [
                    'model_key' => $modelId,
                    'display_name' => $this->humanize($modelId),
                    'capabilities' => [AiModelCapability::ImageGeneration->value],
                    'temperature_supported' => false,
                    'is_default' => false,
                    'is_active' => true,
                    'sort_order' => $nextSortOrder++,
                ]);

                $alreadyKnown[] = $modelId;
                $created++;

                continue;
            }

            // Real, observed gap this same registry overhaul fixes:
            // speech-to-text/text-to-speech models are just as unable to
            // answer a normal chat/completions call as an image model is
            // - registering one as "chat" (the isChatCapable() check
            // below would otherwise skip them entirely as NON_CHAT_MARKERS,
            // which is correct for capabilities, but they still deserve a
            // real category/registry row instead of being silently
            // dropped from the registry altogether).
            if ($this->isSpeechToTextModel($modelId)) {
                $provider->models()->create($registryFields + [
                    'model_key' => $modelId,
                    'display_name' => $this->humanize($modelId),
                    'capabilities' => [AiModelCapability::SpeechToText->value],
                    'temperature_supported' => false,
                    'is_default' => false,
                    'is_active' => true,
                    'sort_order' => $nextSortOrder++,
                ]);

                $alreadyKnown[] = $modelId;
                $created++;

                continue;
            }

            if ($this->isTextToSpeechModel($modelId)) {
                $provider->models()->create($registryFields + [
                    'model_key' => $modelId,
                    'display_name' => $this->humanize($modelId),
                    'capabilities' => [AiModelCapability::TextToSpeech->value],
                    'temperature_supported' => false,
                    'is_default' => false,
                    'is_active' => true,
                    'sort_order' => $nextSortOrder++,
                ]);

                $alreadyKnown[] = $modelId;
                $created++;

                continue;
            }

            // Same reasoning as the speech-to-text/text-to-speech branches
            // above: a realtime voice model cannot answer a normal
            // chat/completions call, so it must never be silently dropped
            // into the generic "not chat capable" branch below with an
            // EMPTY capabilities array and is_active=false - it has a
            // real capability (AiRealtimeService now has a genuine
            // connector-backed way to start a session with it), so it is
            // registered active and selectable by routing like every
            // other real capability above it.
            if ($this->isRealtimeVoiceModel($modelId)) {
                $provider->models()->create($registryFields + [
                    'model_key' => $modelId,
                    'display_name' => $this->humanize($modelId),
                    'capabilities' => [AiModelCapability::Realtime->value],
                    'temperature_supported' => false,
                    'is_default' => false,
                    'is_active' => true,
                    'sort_order' => $nextSortOrder++,
                ]);

                $alreadyKnown[] = $modelId;
                $created++;

                continue;
            }

            if ($this->isEmbeddingModel($modelId)) {
                $embeddingLimits = $this->knownLimits($modelId);

                $provider->models()->create($registryFields + [
                    'model_key' => $modelId,
                    'display_name' => $this->humanize($modelId),
                    'capabilities' => [AiModelCapability::Embeddings->value],
                    'temperature_supported' => false,
                    'context_window' => $embeddingLimits['context_window'],
                    'max_output_tokens' => $embeddingLimits['max_output_tokens'],
                    'is_default' => false,
                    'is_active' => false,
                    'sort_order' => $nextSortOrder++,
                ]);

                $alreadyKnown[] = $modelId;
                $created++;

                continue;
            }

            if (! $this->isChatCapable($modelId)) {
                // Still recorded in the registry (category + lifecycle
                // metadata) so it shows up in the admin screen instead of
                // vanishing silently, but with NO capabilities at all -
                // never guessed, never defaulted, never made selectable
                // by routing - since none of this codebase's connectors
                // implement a real call for whatever this category is yet
                // (video generation, moderation, etc.).
                $provider->models()->create($registryFields + [
                    'model_key' => $modelId,
                    'display_name' => $this->humanize($modelId),
                    'capabilities' => [],
                    'temperature_supported' => $this->supportsTemperature($modelId),
                    'is_default' => false,
                    'is_active' => false,
                    'sort_order' => $nextSortOrder++,
                ]);

                $alreadyKnown[] = $modelId;
                $skipped++;

                continue;
            }

            $limits = $this->knownLimits($modelId);

            $provider->models()->create($registryFields + [
                'model_key' => $modelId,
                'display_name' => $this->humanize($modelId),
                'capabilities' => $this->inferCapabilities($modelId),
                'temperature_supported' => $this->supportsTemperature($modelId),
                'context_window' => $limits['context_window'],
                'max_output_tokens' => $limits['max_output_tokens'],
                'is_default' => ! $hasDefault && ! $chatModelCreated,
                'is_active' => true,
                'sort_order' => $nextSortOrder++,
            ]);

            $alreadyKnown[] = $modelId;
            $created++;
            $chatModelCreated = true;
        }

        // Business gap fix (BEFORE this: a model that vanished from the
        // provider's real list stayed registered forever with no signal
        // at all, still fully selectable by routing). Never deleted -
        // deprecated/deactivated, per this platform's own explicit rule
        // (see the registry migration's docblock) - the admin can always
        // reactivate it manually, and it reactivates itself automatically
        // the moment it reappears in a later sync (handled above).
        $stillPresent = array_filter($modelIds, fn ($id) => is_string($id) && $id !== '');
        $deprecated = 0;

        foreach ($existingRows as $row) {
            if ($row->status === 'deprecated' || in_array($row->model_key, $stillPresent, true)) {
                continue;
            }

            $provider->models()->where('id', $row->id)->update([
                'status' => 'deprecated',
                'is_active' => false,
                'deprecated_at' => $now,
            ]);

            $deprecated++;
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'updated' => $updated,
            'deprecated' => $deprecated,
            'reactivated' => $reactivated,
            'found' => $found,
        ];
    }

    /**
     * Admin-triggered "recalculate classification" action (Dynamic Model
     * Registry, real gap): sync()'s own already-known branch only ever
     * backfills category/model_family/snapshot/alias when category is
     * still NULL, so it deliberately never touches a row that already
     * has SOME category value - by design, so it can never clobber a
     * category an admin corrected by hand. But that same protection also
     * permanently freezes a row that was mis-categorized by an EARLIER,
     * less accurate version of inferCategory() (e.g. before the Sora/
     * Codex/Embeddings/Moderation/Realtime rules existed) - re-testing
     * the connection can never fix it, because the column is no longer
     * null. This is the deliberate escape hatch: unconditionally
     * recomputes category/model_family/snapshot/alias/needs_review for
     * EVERY currently-registered row of this provider against whatever
     * inferCategory()/parseModelFamily()/parseSnapshot()/detectAlias()
     * say right now - no provider API call, fully deterministic, always
     * safe to re-run. Never touches capabilities/is_active/is_default/
     * temperature/max_tokens/sort_order, which stay exactly what the
     * admin already set.
     *
     * @return array{updated: int, recategorized: int}
     */
    public function normalize(AiProvider $provider): array
    {
        $models = $provider->models()->get([
            'id', 'model_key', 'category', 'temperature_supported', 'context_window', 'max_output_tokens',
        ]);
        $allModelIds = $models->pluck('model_key')->all();

        $updated = 0;
        $recategorized = 0;

        foreach ($models as $model) {
            $category = $this->inferCategory($model->model_key);
            $snapshot = $this->parseSnapshot($model->model_key);
            $aliasOf = $this->detectAlias($model->model_key, $allModelIds);

            if ($model->category !== $category) {
                $recategorized++;
            }

            $payload = [
                // category (and everything derived alongside it below) is
                // 100% rule-derived from the model id, with nothing an
                // admin can independently correct in the UI - so, unlike
                // the three fields below, it is always safe (and the
                // whole point of this action) to unconditionally
                // recompute it every time, never guarded by a null-check.
                'category' => $category,
                'needs_review' => $category === AiModelCategory::Unknown->value,
                'model_family' => $this->parseModelFamily($model->model_key),
                'canonical_model_id' => $snapshot['canonical_model_id'] ?? $aliasOf,
                'is_alias' => $aliasOf !== null,
                'is_snapshot' => $snapshot['is_snapshot'],
                'release_date' => $snapshot['release_date'],
            ];

            // These three, by contrast, CAN be corrected by hand in the
            // edit-capabilities popover (temperature_supported directly;
            // context_window/max_output_tokens as real published figures
            // this platform's own tiny lookup table may not know) - only
            // ever filled in here while still null, exactly like sync()'s
            // own backfill branch, so re-running this action can never
            // silently erase an admin's correction back to unknown.
            if ($model->temperature_supported === null) {
                $payload['temperature_supported'] = $this->supportsTemperature($model->model_key);
            }

            if ($model->context_window === null || $model->max_output_tokens === null) {
                $limits = $this->knownLimits($model->model_key);

                if ($model->context_window === null && $limits['context_window'] !== null) {
                    $payload['context_window'] = $limits['context_window'];
                }

                if ($model->max_output_tokens === null && $limits['max_output_tokens'] !== null) {
                    $payload['max_output_tokens'] = $limits['max_output_tokens'];
                }
            }

            $provider->models()->where('id', $model->id)->update($payload);

            $updated++;
        }

        return ['updated' => $updated, 'recategorized' => $recategorized];
    }

    /**
     * OpenAI-only for now (the only connector with a real editImage()
     * implementation) - gpt-image-1 and the dall-e family. Checked before
     * isChatCapable()/NON_CHAT_MARKERS, which would otherwise skip these
     * entirely (they genuinely cannot answer a chat/completions call).
     *
     * public static so AiModelCapabilityClassifier can apply the exact
     * same hard rule to "Reclassify with AI" results - real, observed gap:
     * that feature asked its own AI classifier to judge every active
     * model's capabilities and trusted the answer completely, with no
     * equivalent safeguard. The classifier (reasoning about the id from
     * its own knowledge, not this code) tagged every gpt-image-family / dall-e-family
     * row "chat" and "vision" alongside "image_generation" - plausible-
     * sounding ("it handles images, so it must see/produce them
     * conversationally") but wrong: these are dedicated image-generation
     * endpoints, unreachable via chat/completions at all. Registered that
     * way, AiRoutingEngine::applyCapabilityPreferences() -> bestModelFor()
     * happily matched them for ordinary vision ("اشرحلي اللي في الصورة")
     * and even plain-text requests, which OpenAI then rejected ("No
     * available capacity", "only supported in v1/responses", 500s) -
     * and enough consecutive failures against the same provider tripped
     * its failover cooldown, blocking totally unrelated chat requests
     * too. Fixed at the deterministic layer (not just the prompt) so no
     * amount of classifier confidence can ever re-introduce this.
     */
    public static function isImageGenerationModel(string $modelId): bool
    {
        $id = Str::lower($modelId);

        return str_contains($id, 'gpt-image') || str_contains($id, 'dall-e');
    }

    protected function isChatCapable(string $modelId): bool
    {
        $id = Str::lower($modelId);

        foreach (self::NON_CHAT_MARKERS as $marker) {
            if (str_contains($id, $marker)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checked BEFORE isChatCapable()'s NON_CHAT_MARKERS gate, which only
     * catches "whisper"/"tts" substrings - real, observed gap: neither
     * "gpt-transcribe" nor "gpt-4o-transcribe" (OpenAI's actual current
     * transcription model ids, verified against the live API reference
     * 2026-09-28) contain any NON_CHAT_MARKERS substring at all, so both
     * would otherwise fall through as if they were ordinary chat models.
     */
    public static function isSpeechToTextModel(string $modelId): bool
    {
        $id = Str::lower($modelId);

        return str_contains($id, 'transcribe') || str_contains($id, 'whisper');
    }

    /**
     * "tts" alone is already one of NON_CHAT_MARKERS (so isChatCapable()
     * already excludes it from "chat"), but until now nothing gave it a
     * real category/capability row at all - it was simply dropped
     * (counted as $skipped) like any other non-chat model this codebase
     * has no connector support for. AiGateway::synthesizeSpeech() now
     * does have real support (see OpenAiConnector::synthesizeSpeech()),
     * so this is checked explicitly, before the generic isChatCapable()
     * skip-and-drop path.
     */
    public static function isTextToSpeechModel(string $modelId): bool
    {
        $id = Str::lower($modelId);

        // "transcribe" is checked first by the caller (isSpeechToTextModel()),
        // so a model id combining both markers (none observed in practice)
        // would already have matched that instead.
        return str_contains($id, 'tts') || str_contains($id, '-speech');
    }

    /**
     * "embedding" is already one of NON_CHAT_MARKERS (isChatCapable()
     * already correctly excludes it from "chat"), but - the same real gap
     * text-to-speech had before AiGateway::synthesizeSpeech() existed -
     * embedding models were simply dropped from the registry entirely
     * (counted as $skipped), even though AiGateway::embed() is a real,
     * already-used connector call (AiKnowledgeIngestionService/
     * AiKnowledgeRetriever's RAG pipeline). Checked explicitly, before
     * the generic isChatCapable() skip-and-drop path, so an embedding
     * model gets a real "embeddings" capability tag instead of an empty
     * one - informational for now (the knowledge pipeline still picks
     * its embedding model from config, not this registry), but no longer
     * a silent gap the admin has no visibility into.
     */
    public static function isEmbeddingModel(string $modelId): bool
    {
        return str_contains(Str::lower($modelId), 'embedding');
    }

    /**
     * Explicit, non-guessing category rules (v2.0 doc §3/§4 of the
     * user's own spec - deliberately NEVER "starts with gpt- = general",
     * which would misclassify gpt-image-2, gpt-transcribe, gpt-5.3-codex,
     * sora-2, etc). Order matters: more specific markers are checked
     * before the broad "looks like a known chat family" fallback.
     * Returns Unknown when nothing matches - the caller flags
     * needs_review=true rather than ever guessing.
     */
    public function inferCategory(string $modelId): string
    {
        $id = Str::lower($modelId);

        if (self::isImageGenerationModel($modelId)) {
            return AiModelCategory::ImageGeneration->value;
        }

        if (str_contains($id, 'sora') || str_contains($id, 'video-generation')) {
            return AiModelCategory::VideoGeneration->value;
        }

        if (self::isSpeechToTextModel($modelId)) {
            return AiModelCategory::SpeechToText->value;
        }

        if (self::isTextToSpeechModel($modelId)) {
            return AiModelCategory::TextToSpeech->value;
        }

        // Checked AFTER the transcription/TTS markers above precisely
        // because a real OpenAI model id combines both, e.g.
        // "gpt-realtime-whisper" - that is Speech-to-Text by the user's
        // own spec, not Realtime, despite containing "realtime" too.
        // "audio" and "live" are checked here too (live catalog gap,
        // Sept 2026: "gpt-audio-1.5", "gpt-live-1") - both are
        // voice/audio-conversation models, not general chat, despite
        // starting with "gpt-" like every general chat model also does.
        if (str_contains($id, 'realtime') || str_contains($id, 'audio') || str_contains($id, 'live')) {
            return AiModelCategory::RealtimeVoice->value;
        }

        if (str_contains($id, 'codex')) {
            return AiModelCategory::Coding->value;
        }

        if (str_contains($id, 'deep-research') || str_contains($id, 'deep_research')) {
            return AiModelCategory::DeepResearch->value;
        }

        if (str_contains($id, 'cyber') || str_contains($id, 'daybreak')) {
            return AiModelCategory::Cybersecurity->value;
        }

        if (str_contains($id, 'rosalind')) {
            return AiModelCategory::LifeSciences->value;
        }

        if (str_contains($id, 'embedding')) {
            return AiModelCategory::Embeddings->value;
        }

        if (str_contains($id, 'moderation') || str_contains($id, 'guard') || str_contains($id, 'safeguard')) {
            return AiModelCategory::Moderation->value;
        }

        $isKnownReasoningFamily = Str::startsWith($id, ['o1', 'o3', 'o4'])
            || str_contains($id, 'reasoning')
            || str_contains($id, 'thinking');

        if ($isKnownReasoningFamily) {
            return AiModelCategory::Reasoning->value;
        }

        // Real, still-listed-but-superseded models (spec example set):
        // davinci-002/babbage-002 (the old completions-only base models),
        // the whole gpt-3.5-turbo family, and the dated pre-gpt-4o gpt-4
        // snapshots (gpt-4-0301/0314/0613, gpt-4-32k*) OpenAI kept serving
        // for backward compatibility long after gpt-4o superseded them.
        // Checked before the general chat-family fallback so these don't
        // get buried in "General" next to today's flagship models - an
        // admin scanning General for a chat model to route to should not
        // have to sift through a decade of superseded ids to find one.
        $isLegacyModel = Str::startsWith($id, [
            'davinci', 'babbage', 'curie', 'ada-',
            'text-davinci', 'text-curie', 'text-babbage', 'text-ada', 'code-davinci',
            'gpt-3.5-turbo', 'gpt-4-0301', 'gpt-4-0314', 'gpt-4-0613', 'gpt-4-32k',
        ]);

        if ($isLegacyModel) {
            return AiModelCategory::Legacy->value;
        }

        $looksLikeKnownChatFamily = Str::startsWith($id, [
            'gpt-', 'o1', 'o3', 'o4', 'claude-', 'gemini-', 'llama', 'mixtral', 'grok', 'deepseek', 'mistral',
        ]);

        if ($looksLikeKnownChatFamily) {
            return AiModelCategory::General->value;
        }

        // Genuinely no rule matched - this is the ONLY case that falls
        // back to Unknown. The caller sets needs_review=true so it shows
        // up in the admin screen for a human to categorize, rather than
        // being silently mis-tagged as General.
        return AiModelCategory::Unknown->value;
    }

    /**
     * Whether OpenAI's real API accepts a `temperature` parameter for
     * this model at all. Two real, documented facts drive this, not a
     * guess: the o-series reasoning models (o1/o3/o4) reject the
     * parameter outright ("Unsupported parameter: 'temperature'"), and
     * every non-chat endpoint (images, audio, embeddings, moderation,
     * video) has no such concept in its request shape to begin with -
     * `temperature` is specifically a chat/completions sampling knob.
     * A genuinely unknown/unrecognized id defaults to true (assume it
     * behaves like an ordinary chat model) rather than hiding a control
     * that might be perfectly valid for it.
     */
    public function supportsTemperature(string $modelId): bool
    {
        $id = Str::lower($modelId);

        $isReasoningFamily = Str::startsWith($id, ['o1', 'o3', 'o4'])
            || str_contains($id, 'reasoning')
            || str_contains($id, 'thinking');

        if ($isReasoningFamily) {
            return false;
        }

        return $this->isChatCapable($modelId);
    }

    /**
     * Best-effort, DELIBERATELY SMALL static lookup of context_window/
     * max_output_tokens for a handful of long-established, publicly
     * documented OpenAI families only - never a guess, never extrapolated
     * to a family not listed here. Every other model (including every
     * newer/less-certain id in this platform's own live catalog) returns
     * null for both, exactly per the explicit "don't default to a made-up
     * number" requirement - the admin can fill in the real published
     * figure by hand once known (see the model's edit-capabilities
     * popover), which this method's result never overwrites once set
     * (see normalize()'s own null-guard for that field).
     *
     * @return array{context_window: ?int, max_output_tokens: ?int}
     */
    public function knownLimits(string $modelId): array
    {
        $family = Str::lower($this->parseModelFamily($modelId));

        $known = [
            'gpt-4o' => [128000, 16384],
            'gpt-4o-mini' => [128000, 16384],
            'gpt-4-turbo' => [128000, 4096],
            'gpt-4.1' => [1047576, 32768],
            'gpt-4.1-mini' => [1047576, 32768],
            'gpt-4.1-nano' => [1047576, 32768],
            'gpt-3.5-turbo' => [16385, 4096],
            'o1' => [200000, 100000],
            'o1-pro' => [200000, 100000],
            'o1-mini' => [128000, 65536],
            'o3' => [200000, 100000],
            'o3-mini' => [200000, 100000],
            'o4-mini' => [200000, 100000],
            'text-embedding-3-small' => [8191, null],
            'text-embedding-3-large' => [8191, null],
            'text-embedding-ada-002' => [8191, null],
        ];

        if (! array_key_exists($family, $known)) {
            return ['context_window' => null, 'max_output_tokens' => null];
        }

        [$contextWindow, $maxOutputTokens] = $known[$family];

        return ['context_window' => $contextWindow, 'max_output_tokens' => $maxOutputTokens];
    }

    /**
     * Strips a trailing dated-snapshot suffix (-YYYY-MM-DD) to get the
     * model's base family, e.g. "gpt-5.4-2026-03-05" -> "gpt-5.4". A
     * model id with no such suffix is its own family.
     */
    public function parseModelFamily(string $modelId): string
    {
        return (string) preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $modelId);
    }

    /**
     * @return array{is_snapshot: bool, release_date: ?string, canonical_model_id: ?string}
     */
    public function parseSnapshot(string $modelId): array
    {
        if (! preg_match('/^(?<family>.+)-(?<date>\d{4}-\d{2}-\d{2})$/', $modelId, $matches)) {
            return ['is_snapshot' => false, 'release_date' => null, 'canonical_model_id' => null];
        }

        return [
            'is_snapshot' => true,
            'release_date' => $matches['date'],
            'canonical_model_id' => $matches['family'],
        ];
    }

    /**
     * Best-effort alias detection (v2.0 doc §7 - explicitly conditioned
     * by the user's own spec on "إذا كانت OpenAI API توفر هذه المعلومة":
     * the real /v1/models endpoint does NOT actually return alias
     * metadata, only a flat id list, so this can only ever be a
     * heuristic, never a guarantee). A "bare" id with no descriptive
     * suffix at all (e.g. "gpt-5.6") is treated as an alias of whichever
     * other id in the SAME fetched batch starts with "gpt-5.6-" (e.g.
     * "gpt-5.6-sol") - picking the alphabetically-first match for a
     * deterministic result. Returns null (never guessed) for any id that
     * already looks like a complete, real model id on its own.
     *
     * @param  list<string>  $allModelIds
     */
    public function detectAlias(string $modelId, array $allModelIds): ?string
    {
        if (! preg_match('/^[a-z]+-[0-9]+(\.[0-9]+)?$/i', $modelId)) {
            return null;
        }

        $siblings = array_values(array_filter($allModelIds, function ($candidate) use ($modelId) {
            return is_string($candidate) && $candidate !== $modelId && str_starts_with($candidate, $modelId.'-');
        }));

        if ($siblings === []) {
            return null;
        }

        sort($siblings);

        return $siblings[0];
    }

    /**
     * Naming-pattern confidence that a model id belongs to a genuinely
     * multimodal (image-input-capable) family - gpt-4o, gpt-4.1, gpt-5,
     * Claude 3+, Gemini, etc. Exposed as a public method (not just inlined
     * inside inferCapabilities()) so AiModelCapabilityClassifier can use
     * the exact same naming rule to guard its own AI-generated answer:
     * the classifier asks a live model to self-report capabilities, and
     * that self-report can be wrong (an observed real case: gpt-4o-mini
     * came back tagged "reasoning" but missing "vision" entirely, which
     * silently made every image message to it fail - AiGateway strips
     * the attached image before the API call whenever the routed model
     * isn't tagged "vision"). A naming-pattern family this confident
     * about should never be silently overridden by one AI call's guess.
     *
     * Excludes the same known false-positive shapes the multimodal check
     * already had to account for: a "-codex" coding variant and both the
     * older "-search-preview" and newer "-search-api" dedicated search
     * variants all still start with a multimodal family's prefix (e.g.
     * "gpt-5-codex", "gpt-4o-mini-search-preview-2025-03-11",
     * "gpt-5-search-api") but are text-(+ live search-)only and cannot
     * actually accept an image - "gpt-5-search-api" in particular was a
     * real, observed gap here: only the "-search-preview" naming was
     * ever excluded, so this newer naming still fell through to "starts
     * with gpt-5" and was wrongly tagged vision.
     */
    public static function isKnownMultimodalModel(string $modelId): bool
    {
        $id = Str::lower($modelId);

        $isCodexVariant = str_contains($id, 'codex');
        $isSearchVariant = str_contains($id, 'search-preview') || str_contains($id, 'search_preview')
            || str_contains($id, 'search-api') || str_contains($id, 'search_api');

        return ! $isCodexVariant && ! $isSearchVariant && (
            str_contains($id, 'vision')
            || str_contains($id, 'omni')
            || str_contains($id, 'gpt-4-turbo')
            || Str::startsWith($id, ['gpt-4o', 'gpt-4.1', 'gpt-4.5', 'gpt-5', 'claude-3', 'claude-4', 'claude-5', 'gemini'])
        );
    }

    /**
     * @return list<string>
     */
    protected function inferCapabilities(string $modelId): array
    {
        $capabilities = [AiModelCapability::Chat->value];

        if (self::isKnownMultimodalModel($modelId)) {
            $capabilities[] = AiModelCapability::Vision->value;
        }

        $id = Str::lower($modelId);

        $isKnownReasoningFamily = Str::startsWith($id, ['o1', 'o3', 'o4'])
            || str_contains($id, 'reasoning')
            || str_contains($id, 'thinking');

        if ($isKnownReasoningFamily) {
            $capabilities[] = AiModelCapability::Reasoning->value;
        }

        if (str_contains($id, 'codex') || str_contains($id, 'code')) {
            $capabilities[] = AiModelCapability::Coding->value;
        }

        // Real, observed gap this closes: isKnownMultimodalModel() already
        // had to special-case BOTH "-search-preview" (the older dedicated
        // search models, e.g. gpt-4o-search-preview) and "-search-api"
        // (the newer naming, e.g. gpt-5-search-api) as text-only false
        // positives for vision - but nothing ever tagged them with the
        // "web_search" capability they actually DO have, so a registered
        // search model could never be matched by AiRoutingEngine for a
        // "سعر الدولار اليوم"/"latest news" request; it would only ever
        // be picked for a plain chat turn, same as any other chat model.
        $isSearchModel = str_contains($id, 'search-preview') || str_contains($id, 'search_preview')
            || str_contains($id, 'search-api') || str_contains($id, 'search_api');

        if ($isSearchModel) {
            $capabilities[] = AiModelCapability::WebSearch->value;
        }

        return $capabilities;
    }

    /**
     * Checked BEFORE isChatCapable()'s NON_CHAT_MARKERS gate, mirroring
     * isSpeechToTextModel()/isTextToSpeechModel() exactly - a realtime
     * voice model (gpt-realtime*, gpt-audio*, gpt-live*) cannot answer a
     * normal chat/completions call at all, it only works over the
     * dedicated Realtime session API (see AiRealtimeService), so it must
     * never be tagged "chat" or picked as the provider's text default.
     * Checked AFTER the transcription/TTS markers for the same reason
     * inferCategory() checks them in this order: a model id combining
     * both, e.g. "gpt-realtime-whisper", is Speech-to-Text by the user's
     * own spec despite containing "realtime" too.
     */
    public static function isRealtimeVoiceModel(string $modelId): bool
    {
        if (self::isSpeechToTextModel($modelId) || self::isTextToSpeechModel($modelId)) {
            return false;
        }

        $id = Str::lower($modelId);

        return str_contains($id, 'realtime') || str_contains($id, 'audio') || str_contains($id, 'live');
    }

    protected function humanize(string $modelId): string
    {
        return Str::of($modelId)
            ->replace(['-', '_'], ' ')
            ->title()
            ->toString();
    }
}
