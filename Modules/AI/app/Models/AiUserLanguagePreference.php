<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiUserLanguagePreference extends Model
{
    public const MODE_FOLLOW_INPUT = 'follow_input';

    public const MODE_FIXED = 'fixed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'language_id',
        'variant_id',
        'auto_detect',
        'response_language_mode',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_detect' => 'boolean',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(AiLanguage::class, 'language_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(AiLanguageVariant::class, 'variant_id');
    }
}
