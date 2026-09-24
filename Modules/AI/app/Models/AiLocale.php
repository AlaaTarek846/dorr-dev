<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiLocale extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'language_id',
        'code',
        'name',
        'settings',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(AiLanguage::class, 'language_id');
    }
}
