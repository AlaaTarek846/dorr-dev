<?php

namespace Modules\AI\Models;

use App\Models\Concerns\HasTranslations;
use App\Models\Language;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasFlatTranslatedText;

/**
 * A dialect / style of a language (Egyptian Arabic, MSA, Standard English...) a user may pick in their AI language
 * settings; the chat then answers in that style (AiChatLanguageResolver). A catalog entity like Country: the name
 * people read lives in ai_language_variant_translations; the code and the style are technical and not translated;
 * `status` is the on/off switch and deleting moves it to the trash. One default variant per language
 * (AiLanguageVariantService).
 *
 * Switched off or in the trash = not offered to users and never used by the chat.
 *
 * Back-compat (HasFlatTranslatedText): `$variant->name` reads the current language, `$variant->is_active` reads
 * `status`, and flat text (`create(['name' => ...])`) is written into every stored language.
 */
class AiLanguageVariant extends Model
{
    use HasFlatTranslatedText, HasTranslations, SearchFilterTrait, SoftDeletes {
        HasFlatTranslatedText::syncPendingTranslations insteadof HasTranslations;
    }

    public const STYLE_FORMAL = 'formal';

    public const STYLE_CONVERSATIONAL = 'conversational';

    /** The name is shown wherever a variant is (preferences, evaluations), so its translations come along. */
    protected $with = ['translations'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'language_id',
        'code',
        'name',
        'style',
        'is_default',
        'status',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Pending flat text was just written to ai_language_variant_translations: drop the cached relations.
        static::saved(function (self $variant): void {
            $variant->unsetRelation('translations')->unsetRelation('translation');
        });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AiLanguageVariantTranslation::class, 'ai_language_variant_id');
    }

    protected function translationModel(): string
    {
        return AiLanguageVariantTranslation::class;
    }

    /** Translated columns the admin list search looks in (SearchFilterTrait). */
    protected function translationSearchColumns(): array
    {
        return ['name'];
    }

    // Root-cause fix (languages consolidation): used to belong to the
    // AI module's own now-removed "ai_languages" table - points at the
    // platform's single general Language model instead, matching the
    // languages_consolidation migrations.
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'language_id');
    }

    /** User language preferences that picked this variant (deleting it for good clears their variant - nullOnDelete). */
    public function preferences(): HasMany
    {
        return $this->hasMany(AiUserLanguagePreference::class, 'variant_id');
    }

    /** Language evaluations of this variant, the trash included (deleting it for good clears their variant - nullOnDelete). */
    public function evaluations(): HasMany
    {
        return $this->hasMany(AiLanguageEvaluation::class, 'variant_id')->withTrashed();
    }
}
