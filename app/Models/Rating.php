<?php

namespace App\Models;

use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Provider\Models\Provider;

/**
 * A star rating with an optional comment. Polymorphic on both sides:
 *  - `author`   — who rated (a user from the mobile app);
 *  - `rateable` — what was rated (a service category, a provider…); empty means the app itself.
 *
 * Below 4 stars (quarter-star steps allowed) is internal feedback (`type = feedback`) and stay in the dashboard; 4–5 stars are saved the same way
 * (`type = review`) and the app then asks the store for an in-app review. Nothing is ever posted to the store from here.
 */
class Rating extends Model
{
    use SearchFilterTrait;

    public const TYPE_FEEDBACK = 'feedback';

    public const TYPE_REVIEW = 'review';

    /** From this many stars up a rating is a "review" (the app may show the store's own review prompt). */
    public const REVIEW_MIN_STARS = 4;

    /**
     * What a client may rate, by the short name it sends. Anything else is rejected.
     *
     * @var array<string, class-string<Model>>
     */
    public const RATEABLES = [
        'service' => ServiceCategory::class,
        'provider' => Provider::class,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'author_type',
        'author_id',
        'rateable_type',
        'rateable_id',
        'unique_key',
        'stars',
        'type',
        'comment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stars' => 'decimal:2',
        ];
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    public function rateable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function typeForStars(float $stars): string
    {
        return $stars >= self::REVIEW_MIN_STARS ? self::TYPE_REVIEW : self::TYPE_FEEDBACK;
    }

    /**
     * The "one rating per author per thing" key. A missing rateable is the app itself.
     */
    public static function uniqueKeyFor(Model $author, ?Model $rateable): string
    {
        return hash('sha256', implode('|', [
            $author->getMorphClass(),
            $author->getKey(),
            $rateable?->getMorphClass() ?? 'app',
            $rateable?->getKey() ?? 0,
        ]));
    }

    /**
     * The short name of a rateable class (the reverse of [self::RATEABLES]), for responses.
     */
    public static function aliasFor(?string $class): ?string
    {
        return $class === null ? null : (array_search($class, self::RATEABLES, true) ?: class_basename($class));
    }
}
