<?php

namespace Modules\AI\Models;

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

    public function language(): BelongsTo
    {
        return $this->belongsTo(AiLanguage::class, 'language_id');
    }
}
