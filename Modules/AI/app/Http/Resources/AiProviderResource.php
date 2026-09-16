<?php

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AiProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $config = config("ai.providers.{$this->key}", []);

        $isLive = ! empty($this->available_models);
        $models = $isLive ? $this->available_models : Arr::get($config, 'models', []);

        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'is_enabled' => (bool) $this->is_enabled,
            'model' => $this->model,
            'available_models' => self::groupModels($models),
            'available_models_is_live' => $isLive,
            'available_models_synced_at' => $this->available_models_synced_at?->toISOString(),
            'default_model' => Arr::get($config, 'default_model'),
            'base_url' => $this->base_url,
            'default_base_url' => Arr::get($config, 'base_url'),
            'temperature' => $this->temperature !== null ? (float) $this->temperature : null,
            'max_tokens' => $this->max_tokens,
            'organization' => Arr::get($this->extra ?? [], 'organization'),
            'docs_url' => Arr::get($config, 'docs_url'),
            'has_free_tier' => (bool) Arr::get($config, 'has_free_tier', false),
            'pricing_note' => Arr::get($config, 'pricing_note'),
            'has_api_key' => $this->hasApiKey(),
            'api_key_preview' => $this->maskedApiKey(),
            'last_tested_at' => $this->last_tested_at?->toISOString(),
            'last_test_status' => $this->last_test_status,
            'last_test_message' => $this->last_test_message,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Split a flat model id list into labeled groups (chat, moderation,
     * speech-to-text, text-to-speech) based on well-known naming patterns,
     * so provider catalogs that mix model types (e.g. Groq) render as
     * sensible <optgroup>s on the frontend instead of one long flat list.
     *
     * @param  list<string>  $models
     * @return array<string, list<string>>
     */
    protected static function groupModels(array $models): array
    {
        $groups = [
            'chat' => [],
            'moderation' => [],
            'speech_to_text' => [],
            'text_to_speech' => [],
        ];

        foreach ($models as $model) {
            $groups[self::categorize($model)][] = $model;
        }

        return array_filter($groups, fn (array $group) => $group !== []);
    }

    protected static function categorize(string $model): string
    {
        $id = Str::lower($model);

        return match (true) {
            str_contains($id, 'whisper') => 'speech_to_text',
            str_contains($id, 'orpheus') || str_contains($id, 'tts') => 'text_to_speech',
            str_contains($id, 'guard') || str_contains($id, 'safeguard') || str_contains($id, 'moderation') => 'moderation',
            default => 'chat',
        };
    }
}
