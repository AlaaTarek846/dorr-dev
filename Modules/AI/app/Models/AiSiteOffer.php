<?php

namespace Modules\AI\Models;

use App\Models\Concerns\HasTranslations;
use App\Models\Country;
use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasFlatTranslatedText;

/**
 * A website-builder product sold from the wallet: a whole site (`site`), more AI edits for an existing site
 * (`edit_pack`), or the one source-download switch (`source_download`, managed by its own screen). A catalog entity
 * like Country: its customer-facing text (name, description) lives in ai_site_offer_translations, `status` is the
 * on/off switch, and deleting moves it to the trash.
 *
 * Back-compat (HasFlatTranslatedText): `$offer->name / description` read the current language, `$offer->is_active`
 * reads `status`, and flat text (`create(['name' => ...])`) is written into every stored language.
 */
class AiSiteOffer extends Model
{
    use HasFlatTranslatedText, HasTranslations, SearchFilterTrait, SoftDeletes {
        HasFlatTranslatedText::syncPendingTranslations insteadof HasTranslations;
    }

    protected $table = 'ai_site_offers';

    /** The offer text is shown wherever an offer is, so its translations always come along (offers are a handful). */
    protected $with = ['translations'];

    protected $fillable = ['name', 'code', 'kind', 'description', 'generations_included', 'status', 'is_active', 'is_free', 'sort_order'];

    public const KIND_SITE = 'site';

    public const KIND_EDIT_PACK = 'edit_pack';

    public const KIND_SOURCE_DOWNLOAD = 'source_download';

    protected function casts(): array
    {
        return ['generations_included' => 'integer', 'status' => 'boolean', 'is_free' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        // Pending flat text was just written to ai_site_offer_translations (HasTranslations' saved hook runs first):
        // drop the cached relations so the next read sees it.
        static::saved(function (self $offer): void {
            $offer->unsetRelation('translations')->unsetRelation('translation');
        });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AiSiteOfferTranslation::class, 'ai_site_offer_id');
    }

    protected function translationModel(): string
    {
        return AiSiteOfferTranslation::class;
    }

    /** Translated columns the admin list search looks in (SearchFilterTrait). */
    protected function translationSearchColumns(): array
    {
        return ['name', 'description'];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(AiSitePurchase::class, 'offer_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(AiSiteOfferPrice::class, 'offer_id');
    }

    /**
     * @return array{price: float, currency: string}|null null = not sold in this country
     */
    public function priceFor(?Country $country): ?array
    {
        if ($country === null) {
            return null;
        }

        $row = $this->prices()->where('country_id', $country->id)->with('currency')->first();

        if ($row === null || $row->currency === null) {
            return null;
        }

        return ['price' => (float) $row->price, 'currency' => (string) $row->currency->code];
    }
}
