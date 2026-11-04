<?php

namespace Modules\Freelance\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;

/**
 * A promo banner on the freelancer app's home, the client's freelance hub in the Dorr user app, or both ([audience]): a title (and a line and a button text) in Arabic and English, an optional image on the
 * public disk, and where a tap leads — nowhere, a page of the app, or a web page. Shown while on and between its dates, in its country or
 * everywhere when it has none.
 */
class FreelancePromo extends Model implements HasMedia
{
    use HasMediaTrait;

    public const NONE = 'none';

    public const SCREEN = 'screen';

    public const URL = 'url';

    public const TARGETS = [self::NONE, self::SCREEN, self::URL];

    public const FREELANCER = 'freelancer';

    public const CLIENT = 'client';

    public const BOTH = 'both';

    /** Whose home the banner shows on. */
    public const AUDIENCES = [self::FREELANCER, self::CLIENT, self::BOTH];

    /** The pages of the app a banner may open, with the country feature each needs (null: always there). */
    public const SCREENS = [
        'projects' => 'projects', 'invitations' => 'invitations', 'plans' => 'subscriptions', 'insights' => 'projects',
        'services' => 'services', 'consultations' => 'consultations', 'special_offers' => 'special_offers', 'loyalty' => 'loyalty',
        'community' => 'community', 'skill_tests' => 'skill_tests', 'verification' => null, 'coach' => null, 'portfolio' => null,
        'wallet' => null, 'availability' => null, 'capacity' => null,
    ];

    /** The pages of the client's freelance hub a banner may open, with the country feature each needs (null: always there). */
    public const CLIENT_SCREENS = [
        'projects' => 'projects', 'new_project' => 'projects', 'services' => 'services', 'orders' => 'services',
        'consultations' => 'consultations', 'my_consultations' => 'consultations', 'special_offers' => 'special_offers', 'loyalty' => 'loyalty',
        'favorites' => null,
    ];

    protected $table = 'freelance_promos';

    protected $fillable = [
        'uuid', 'title', 'body', 'cta', 'target_type', 'target', 'audience', 'country_id', 'starts_at', 'ends_at', 'sort_order', 'is_active', 'created_by_admin_id',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FreelancePromo $p) => $p->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'title' => 'array', 'body' => 'array', 'cta' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime',
            'sort_order' => 'integer', 'is_active' => 'boolean', 'country_id' => 'integer',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Country::class);
    }

    /**
     * The pages a banner for [audience] may open: each app's own, and for both apps the pages both have.
     *
     * @return array<string, string|null>
     */
    public static function screensFor(string $audience): array
    {
        return match ($audience) {
            self::CLIENT => self::CLIENT_SCREENS,
            self::BOTH => array_intersect_key(self::SCREENS, self::CLIENT_SCREENS),
            default => self::SCREENS,
        };
    }

    /** The banners an app shows: its own and the ones for both. */
    public function scopeFor(Builder $q, string $app): Builder
    {
        return $q->whereIn('audience', [$app, self::BOTH]);
    }

    /** On, started and not over, for a country (or every country). */
    public function scopeShowing(Builder $q, ?int $countryId): Builder
    {
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(fn ($w) => $w->whereNull('country_id')->when($countryId !== null, fn ($c) => $c->orWhere('country_id', $countryId)));
    }

    /** A field in a language (the app's, else Arabic, else English). */
    public function text(string $field, ?string $locale = null): ?string
    {
        $values = (array) $this->{$field};
        $locale ??= app()->getLocale();
        $value = $values[$locale] ?? null;

        return filled($value) ? $value : (filled($values['ar'] ?? null) ? $values['ar'] : ($values['en'] ?? null));
    }

    public function setImage(?UploadedFile $file): void
    {
        $this->setSingleMedia('image', $file);
    }

    public function imagePath(): ?string
    {
        return $this->getSingleMediaUrl('image') ?: null;
    }
}
