<?php

namespace Modules\User\Models;

use App\Models\MobileAppFont;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMobileAppearance extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'uses_default_colors',
        'custom_light_tokens',
        'custom_dark_tokens',
        'mobile_app_font_id',
        'dark_mode',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uses_default_colors' => 'boolean',
            'custom_light_tokens' => 'array',
            'custom_dark_tokens' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function font(): BelongsTo
    {
        return $this->belongsTo(MobileAppFont::class, 'mobile_app_font_id');
    }
}
