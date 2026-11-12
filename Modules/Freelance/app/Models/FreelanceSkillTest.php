<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * How the test of one skill is set (FL-L08): when it is on, how many questions an attempt draws, for how long, and what passes. FS-602: the score
 * that makes an intermediate, and whether advanced / expert are reached by a practical challenge (`challenge_brief` = {ar, en}).
 */
class FreelanceSkillTest extends Model
{
    protected $fillable = ['freelance_skill_id', 'status', 'questions_count', 'minutes', 'pass_percent', 'intermediate_percent', 'retake_hours', 'challenge_enabled', 'challenge_brief'];

    protected function casts(): array
    {
        return ['status' => 'boolean', 'questions_count' => 'integer', 'minutes' => 'integer', 'pass_percent' => 'integer', 'intermediate_percent' => 'integer', 'retake_hours' => 'integer', 'challenge_enabled' => 'boolean', 'challenge_brief' => 'array'];
    }

    /** FS-602: the text of a {ar, en} field in the request's language, the other one when it is empty (a raw JSON string is read too). */
    public static function pickText(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            return null;
        }
        $locale = app()->getLocale();
        $text = trim((string) ($value[$locale] ?? ''));
        if ($text === '') {
            $text = trim((string) ($value[$locale === 'ar' ? 'en' : 'ar'] ?? ''));
        }

        return $text === '' ? null : $text;
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(FreelanceSkill::class, 'freelance_skill_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(FreelanceSkillQuestion::class, 'freelance_skill_id', 'freelance_skill_id');
    }
}
