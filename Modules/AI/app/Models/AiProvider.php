<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiProvider extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'is_enabled',
        'api_key',
        'model',
        'base_url',
        'temperature',
        'max_tokens',
        'extra',
        'available_models',
        'available_models_synced_at',
        'last_test_status',
        'last_test_message',
        'last_tested_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'api_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'api_key' => 'encrypted',
            'temperature' => 'decimal:2',
            'max_tokens' => 'integer',
            'extra' => 'array',
            'available_models' => 'array',
            'available_models_synced_at' => 'datetime',
            'last_tested_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    public function hasApiKey(): bool
    {
        return filled($this->api_key);
    }

    public function maskedApiKey(): ?string
    {
        if (! $this->hasApiKey()) {
            return null;
        }

        $key = (string) $this->api_key;
        $visible = substr($key, -4);

        return str_repeat('•', 8).$visible;
    }
}
