<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFeatureFlag extends Model
{
    public const TARGET_PROVIDER = 'provider';

    public const TARGET_MODEL = 'model';

    public const TARGET_TOOL = 'tool';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'target_type',
        'provider_id',
        'model_key',
        'tool_key',
        'country_code',
        'domain',
        'environment',
        'is_enabled',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
