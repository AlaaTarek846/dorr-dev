<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiLanguage extends Model
{
    public const DIRECTION_LTR = 'ltr';

    public const DIRECTION_RTL = 'rtl';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'direction',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function locales(): HasMany
    {
        return $this->hasMany(AiLocale::class, 'language_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(AiLanguageVariant::class, 'language_id');
    }
}
