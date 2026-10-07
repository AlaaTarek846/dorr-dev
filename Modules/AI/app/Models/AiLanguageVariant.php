<?php

namespace Modules\AI\Models;

use App\Models\Language;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiLanguageVariant extends Model
{
    public const STYLE_FORMAL = 'formal';

    public const STYLE_CONVERSATIONAL = 'conversational';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'language_id',
        'code',
        'name',
        'style',
        'is_default',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // Root-cause fix (languages consolidation): used to belong to the
    // AI module's own now-removed "ai_languages" table - points at the
    // platform's single general Language model instead, matching the
    // languages_consolidation migrations.
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'language_id');
    }
}
