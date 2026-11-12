<?php

namespace Modules\AI\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasFlatTranslatedText;

/**
 * A data policy: how long a class of data is kept and whether it is minimized / may leave to an external provider.
 * A catalog entity like Country: the name / description the admin reads live in ai_data_policy_translations; the
 * classification, retention days and switches are technical and not translated; `status` is the on/off switch and
 * deleting moves it to the trash.
 *
 * The engine reads the newest switched-on policy of the "personal" class (activePersonal()): its retention_days drive
 * ai:enforce-retention and its minimization_enabled the log redaction (AiChatService, AiTaskRunner). Switched off or
 * in the trash = not read.
 *
 * Back-compat (HasFlatTranslatedText): `$policy->name / description` read the current language, `$policy->is_active`
 * reads `status`, and flat text (`create(['name' => ...])`) is written into every stored language.
 */
class AiDataPolicy extends Model
{
    use HasFactory, HasFlatTranslatedText, HasTranslations, SearchFilterTrait, SoftDeletes {
        HasFlatTranslatedText::syncPendingTranslations insteadof HasTranslations;
    }

    const CLASSIFICATION_PUBLIC = 'public';

    const CLASSIFICATION_INTERNAL = 'internal';

    const CLASSIFICATION_CONFIDENTIAL = 'confidential';

    const CLASSIFICATION_PERSONAL = 'personal';

    const CLASSIFICATION_SECRET = 'secret';

    /** Every classification the form offers. */
    public const CLASSIFICATIONS = [
        self::CLASSIFICATION_PUBLIC,
        self::CLASSIFICATION_INTERNAL,
        self::CLASSIFICATION_CONFIDENTIAL,
        self::CLASSIFICATION_PERSONAL,
        self::CLASSIFICATION_SECRET,
    ];

    protected $with = ['translations'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'data_classification',
        'retention_days',
        'consent_required',
        'minimization_enabled',
        'external_provider_allowed',
        'description',
        'status',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'consent_required' => 'boolean',
            'minimization_enabled' => 'boolean',
            'external_provider_allowed' => 'boolean',
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Pending flat text was just written to ai_data_policy_translations (HasTranslations' saved hook runs first):
        // drop the cached relations so the next read sees it.
        static::saved(function (self $policy): void {
            $policy->unsetRelation('translations')->unsetRelation('translation');
        });
    }

    /**
     * The policy the engine applies to personal data: the newest switched-on one of that class (SoftDeletes leaves
     * the trash out). Null = none configured.
     */
    public static function activePersonal(): ?self
    {
        return static::query()
            ->where('data_classification', self::CLASSIFICATION_PERSONAL)
            ->where('status', true)
            ->orderByDesc('id')
            ->first();
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AiDataPolicyTranslation::class, 'ai_data_policy_id');
    }

    protected function translationModel(): string
    {
        return AiDataPolicyTranslation::class;
    }

    /** Translated columns the admin list search looks in (SearchFilterTrait). */
    protected function translationSearchColumns(): array
    {
        return ['name', 'description'];
    }
}
