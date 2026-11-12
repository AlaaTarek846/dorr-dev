<?php

namespace Modules\AI\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasFlatTranslatedText;

/**
 * A kind of chat request (chat, support, content_generation…) the classifier tags a message with, so routing rules
 * can send each kind to its own provider / model. A catalog entity like Country: the text the admin reads (name,
 * description) lives in ai_intent_translations; `key` is the technical code (AiIntentClassifier looks it up) and is
 * never translated; `status` is the on/off switch and deleting moves it to the trash.
 *
 * Switched off or in the trash = never classified (AiIntentClassifier), so the rules aimed at it stop applying.
 *
 * Back-compat (HasFlatTranslatedText): `$intent->name / description` read the current language, `$intent->is_active`
 * reads `status`, and flat text (`create(['name' => ...])`) is written into every stored language.
 */
class AiIntent extends Model
{
    use HasFlatTranslatedText, HasTranslations, SearchFilterTrait, SoftDeletes {
        HasFlatTranslatedText::syncPendingTranslations insteadof HasTranslations;
    }

    /** The name is shown wherever an intent is (rules, requests, metrics), so its translations always come along. */
    protected $with = ['translations'];

    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'name', 'description', 'status', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Pending flat text was just written to ai_intent_translations (HasTranslations' saved hook runs first):
        // drop the cached relations so the next read sees it.
        static::saved(function (self $intent): void {
            $intent->unsetRelation('translations')->unsetRelation('translation');
        });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AiIntentTranslation::class, 'ai_intent_id');
    }

    protected function translationModel(): string
    {
        return AiIntentTranslation::class;
    }

    /** Translated columns the admin list search looks in (SearchFilterTrait). */
    protected function translationSearchColumns(): array
    {
        return ['name', 'description'];
    }

    /** Every routing rule aimed at this intent - in the trash too (deleting the intent for good would turn them into "any intent" rules). */
    public function routingRules(): HasMany
    {
        return $this->hasMany(AiRoutingRule::class, 'intent_id')->withTrashed();
    }

    /** The chat requests classified with this intent (ai_requests) - history that names it. */
    public function requests(): HasMany
    {
        return $this->hasMany(AiRequest::class, 'intent_id');
    }
}
