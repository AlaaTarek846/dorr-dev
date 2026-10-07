<?php

namespace Modules\AI\Models;

use App\Models\Language;
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

    // Root-cause fix (languages consolidation): used to belong to the
    // AI module's own now-removed "ai_languages" table - points at the
    // platform's single general Language model instead, matching the
    // languages_consolidation migrations.
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'language_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(AiLanguageVariant::class, 'variant_id');
    }
}
