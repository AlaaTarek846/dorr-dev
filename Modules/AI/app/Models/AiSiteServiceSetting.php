<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

/** One row: whether the "build it with a person" option exists in the apps, and how long a quote stays valid. */
class AiSiteServiceSetting extends Model
{
    protected $table = 'ai_site_service_settings';

    protected $fillable = ['is_enabled', 'quote_valid_days', 'intro_ar', 'intro_en'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'quote_valid_days' => 'integer'];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['is_enabled' => true, 'quote_valid_days' => 7]);
    }

    public function intro(): ?string
    {
        return app()->getLocale() === 'ar' ? ($this->intro_ar ?: $this->intro_en) : ($this->intro_en ?: $this->intro_ar);
    }
}
