<?php

namespace Modules\Rides\Services\Lifecycle;

use App\Models\Rating;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\RatingTarget;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\DriverRideProfile;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideRatingTag;
use Modules\Rides\Models\RiderRideProfile;
use Modules\Rides\Services\Stats\DriverPoints;
use Modules\User\Models\User;

/**
 * Rating after a completed ride (R-140..145, D-186, O-41): each side rates the other once, with stars,
 * a score per criterion (the policy's `rating.driver_criteria` / `rating.rider_criteria`), chips and a
 * comment. Given only the criteria, the overall stars are their average. Stored in the shared `ratings` table (rateable = the ride, author = who
 * rated) so the dashboard sees them with the rest; the averages live on the ride profiles.
 */
class RideRatingService
{
    public function __construct(private readonly DriverPoints $points) {}

    /**
     * @param  list<int>  $tagIds
     * @param  array<string, int>  $scores  criterion => 1..5
     */
    public function byRider(User $rider, Ride $ride, ?float $stars, array $tagIds, ?string $comment, array $scores = []): Rating
    {
        if ($ride->user_id !== $rider->id) {
            throw new RidesException('ride_not_found', 404);
        }

        $rating = $this->store($rider, $ride, $stars, $tagIds, $comment, RatingTarget::Driver, $scores);
        $stars = (float) $rating->stars;
        $this->refreshAverage(DriverRideProfile::class, 'driver_id', $ride->driver_id, User::class);
        if ($stars >= 5) {
            $this->points->award($ride->driver_id, 'five_stars', $ride);
        }

        return $rating;
    }

    /**
     * @param  list<int>  $tagIds
     * @param  array<string, int>  $scores  criterion => 1..5
     */
    public function byDriver(Driver $driver, Ride $ride, ?float $stars, array $tagIds, ?string $comment, array $scores = []): Rating
    {
        if ($ride->driver_id !== $driver->id) {
            throw new RidesException('not_your_ride', 403);
        }

        $rating = $this->store($driver, $ride, $stars, $tagIds, $comment, RatingTarget::Rider, $scores);
        $this->refreshAverage(RiderRideProfile::class, 'user_id', $ride->user_id, Driver::class);

        return $rating;
    }

    /**
     * The criteria one side scores the other on, with their labels — what the rating sheet shows.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function criteria(Ride $ride, RatingTarget $target): array
    {
        $keys = (array) $ride->policy($target === RatingTarget::Driver ? 'rating.driver_criteria' : 'rating.rider_criteria', []);

        return array_values(array_map(fn ($k) => ['key' => (string) $k, 'label' => __('rides.rating_criteria.'.$k)], array_filter($keys, 'is_string')));
    }

    /**
     * @param  list<int>  $tagIds
     * @param  array<string, int>  $scores
     */
    private function store(Model $author, Ride $ride, ?float $stars, array $tagIds, ?string $comment, RatingTarget $target, array $scores = []): Rating
    {
        // Only the criteria this place asks about, each 1..5; the overall stars default to their average.
        $allowed = array_column(self::criteria($ride, $target), 'key');
        $scores = array_filter(
            array_intersect_key(array_map('intval', $scores), array_flip($allowed)),
            fn (int $s) => $s >= 1 && $s <= 5,
        );
        $stars ??= $scores === [] ? null : round(array_sum($scores) / count($scores), 1);
        if ($stars === null) {
            throw new RidesException('rating_stars_required', 422);
        }

        if ($ride->status !== RideStatus::Completed || $ride->driver_id === null) {
            throw new RidesException('cannot_rate', 409);
        }
        if ($ride->completed_at->diffInHours(now()) > (int) $ride->policy('rating.window_h', 72)) {
            throw new RidesException('rating_window_closed', 409);
        }

        $key = Rating::uniqueKeyFor($author, $ride);
        if (Rating::query()->where('unique_key', $key)->exists()) {
            throw new RidesException('already_rated', 409);
        }

        $tags = RideRatingTag::query()->whereIn('id', $tagIds)->where('target', $target->value)->where('status', true)->pluck('id');

        return DB::transaction(function () use ($author, $ride, $stars, $comment, $key, $tags, $scores) {
            $rating = Rating::query()->create([
                'author_type' => $author->getMorphClass(),
                'author_id' => $author->getKey(),
                'rateable_type' => $ride->getMorphClass(),
                'rateable_id' => $ride->id,
                'unique_key' => $key,
                'stars' => $stars,
                'type' => Rating::typeForStars($stars),
                'comment' => $comment,
            ]);
            foreach ($tags as $tagId) {
                DB::table('ride_rating_tag_selections')->insert(['rating_id' => $rating->id, 'tag_id' => $tagId]);
            }
            foreach ($scores as $criterion => $score) {
                DB::table('ride_rating_scores')->insert(['rating_id' => $rating->id, 'criterion' => $criterion, 'stars' => $score]);
            }

            return $rating;
        });
    }

    /**
     * The person's average over every ride rating written about them by the other side.
     *
     * @param  class-string<Model>  $profile
     * @param  class-string<Model>  $authorClass
     */
    private function refreshAverage(string $profile, string $column, int $personId, string $authorClass): void
    {
        $stats = Rating::query()
            ->where('rateable_type', (new Ride)->getMorphClass())
            ->where('author_type', (new $authorClass)->getMorphClass())
            ->whereIn('rateable_id', Ride::query()->select('id')->where($column, $personId))
            ->selectRaw('COUNT(*) as c, AVG(stars) as a')->first();

        $profile::query()->firstOrCreate([$column => $personId]);
        $profile::query()->whereKey($personId)->update([
            'rating_avg' => round((float) $stats->a, 2),
            'rating_count' => (int) $stats->c,
            'updated_at' => now(),
        ]);
    }
}
