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
 * Rating after a completed ride (R-140..145, D-186): each side rates the other once, with stars,
 * chips and a comment. Stored in the shared `ratings` table (rateable = the ride, author = who
 * rated) so the dashboard sees them with the rest; the averages live on the ride profiles.
 */
class RideRatingService
{
    public function __construct(private readonly DriverPoints $points) {}

    /** @param list<int> $tagIds */
    public function byRider(User $rider, Ride $ride, float $stars, array $tagIds, ?string $comment): Rating
    {
        if ($ride->user_id !== $rider->id) {
            throw new RidesException('ride_not_found', 404);
        }

        $rating = $this->store($rider, $ride, $stars, $tagIds, $comment, RatingTarget::Driver);
        $this->refreshAverage(DriverRideProfile::class, 'driver_id', $ride->driver_id, User::class);
        if ($stars >= 5) {
            $this->points->award($ride->driver_id, 'five_stars', $ride);
        }

        return $rating;
    }

    /** @param list<int> $tagIds */
    public function byDriver(Driver $driver, Ride $ride, float $stars, array $tagIds, ?string $comment): Rating
    {
        if ($ride->driver_id !== $driver->id) {
            throw new RidesException('not_your_ride', 403);
        }

        $rating = $this->store($driver, $ride, $stars, $tagIds, $comment, RatingTarget::Rider);
        $this->refreshAverage(RiderRideProfile::class, 'user_id', $ride->user_id, Driver::class);

        return $rating;
    }

    /** @param list<int> $tagIds */
    private function store(Model $author, Ride $ride, float $stars, array $tagIds, ?string $comment, RatingTarget $target): Rating
    {
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

        return DB::transaction(function () use ($author, $ride, $stars, $comment, $key, $tags) {
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
