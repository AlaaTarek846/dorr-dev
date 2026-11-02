<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One multiple-choice question of a skill's bank (FL-L08). `prompt` and each option's `text` are maps of locale → text; exactly one option
 * is `correct`. Only the admin reads `correct` — a freelancer is sent the texts, never the answer.
 */
class FreelanceSkillQuestion extends Model
{
    protected $fillable = ['freelance_skill_id', 'prompt', 'options', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['prompt' => 'array', 'options' => 'array', 'status' => 'boolean', 'sort_order' => 'integer'];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(FreelanceSkill::class, 'freelance_skill_id');
    }

    /** The text in a locale, falling back to Arabic and then to whatever exists. */
    public static function pick(?array $texts, string $locale): string
    {
        $texts ??= [];

        return (string) ($texts[$locale] ?? $texts['ar'] ?? (reset($texts) ?: ''));
    }
}
