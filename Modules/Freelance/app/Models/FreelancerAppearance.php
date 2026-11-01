<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What one freelancer chose for the app: colours (own palette or the platform's), dark mode and font. One row per freelancer. */
class FreelancerAppearance extends Model
{
    protected $table = 'freelancer_mobile_appearances';

    /** @var list<string> */
    protected $fillable = ['freelancer_id', 'uses_default_colors', 'custom_light_tokens', 'custom_dark_tokens', 'mobile_app_font_id', 'dark_mode'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['uses_default_colors' => 'boolean', 'custom_light_tokens' => 'array', 'custom_dark_tokens' => 'array'];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }
}
